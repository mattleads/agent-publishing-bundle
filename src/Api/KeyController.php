<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Api;

use MattLeads\AgentPublishingBundle\Contract\ApiKeyStorageInterface;
use MattLeads\AgentPublishingBundle\Model\ApiKey;
use MattLeads\AgentPublishingBundle\Model\Scope;
use MattLeads\AgentPublishingBundle\Model\ValidationException;
use MattLeads\AgentPublishingBundle\Section\SectionRegistry;
use MattLeads\AgentPublishingBundle\Security\ApiKeyManager;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Key management for keys holding "keys:manage". A key can only mint keys with a subset of its own scopes and
 * sections, and a section-restricted key only sees and revokes keys inside its sections.
 */
final class KeyController
{
    public function __construct(
        private readonly ApiKeyManager $keys,
        private readonly ApiKeyStorageInterface $storage,
        private readonly ApiKeyNormalizer $normalizer,
        private readonly SectionRegistry $sections,
        private readonly ApiAccess $access,
        private readonly ?ClockInterface $clock = null,
        private readonly bool $enabled = true,
    ) {}

    public function list(): JsonResponse
    {
        if ($denied = $this->disabled() ?? $this->access->deny(Scope::KeysManage)) {
            return $denied;
        }
        $manager = $this->access->key();
        $items = array_values(array_filter($this->storage->all(), static fn(ApiKey $k) => null === $manager || $manager->manages($k)));

        return new JsonResponse(['items' => array_map($this->normalizer->normalize(...), $items), 'total' => \count($items)]);
    }

    public function create(Request $request): JsonResponse
    {
        if ($denied = $this->disabled() ?? $this->access->deny(Scope::KeysManage)) {
            return $denied;
        }
        $manager = $this->access->key();
        try {
            $body = JsonRequest::body($request);
            [$name, $scopes, $sections, $expiresAt] = $this->validate($body, $manager);
        } catch (ValidationException $e) {
            return Problem::json(Response::HTTP_UNPROCESSABLE_ENTITY, 'Validation failed.', ['violations' => $e->violations]);
        }

        $issued = $this->keys->issue($name, $scopes, $sections, $expiresAt);

        return new JsonResponse($this->normalizer->normalize($issued['key']) + ['secret' => $issued['secret']], Response::HTTP_CREATED);
    }

    public function get(string $id): JsonResponse
    {
        if ($denied = $this->disabled() ?? $this->access->deny(Scope::KeysManage)) {
            return $denied;
        }
        $key = $this->visible($id);

        return null === $key ? Problem::json(Response::HTTP_NOT_FOUND, 'Key not found.') : new JsonResponse($this->normalizer->normalize($key));
    }

    public function revoke(string $id): JsonResponse
    {
        if ($denied = $this->disabled() ?? $this->access->deny(Scope::KeysManage)) {
            return $denied;
        }
        $key = $this->visible($id);
        if (null === $key) {
            return Problem::json(Response::HTTP_NOT_FOUND, 'Key not found.');
        }
        $this->keys->revoke($key);

        return new JsonResponse($this->normalizer->normalize($key));
    }

    private function disabled(): ?JsonResponse
    {
        return $this->enabled ? null : Problem::json(Response::HTTP_NOT_FOUND, 'Key management over the API is disabled on this site. Keys are issued with the agent-publishing:api-key:create command.');
    }

    private function visible(string $id): ?ApiKey
    {
        $key = $this->storage->findById($id);
        $manager = $this->access->key();
        if (null === $key || (null !== $manager && !$manager->manages($key))) {
            return null;
        }

        return $key;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{0: string, 1: list<Scope>, 2: list<string>, 3: ?\DateTimeImmutable}
     *
     * @throws ValidationException
     */
    private function validate(array $body, ?ApiKey $manager): array
    {
        $violations = [];
        foreach (array_keys($body) as $field) {
            if (!\in_array($field, ['name', 'scopes', 'sections', 'expires_at'], true)) {
                $violations[] = \sprintf('Unknown field "%s".', $field);
            }
        }

        $name = $body['name'] ?? null;
        if (!\is_string($name) || '' === trim($name) || mb_strlen(trim($name)) > 100) {
            $violations[] = 'Field "name" is required, 1-100 characters.';
            $name = '';
        }

        $scopes = [];
        if (!isset($body['scopes']) || !\is_array($body['scopes']) || [] === $body['scopes']) {
            $violations[] = \sprintf('Field "scopes" is required: a non-empty list of %s.', implode(', ', Scope::values()));
        } else {
            foreach ($body['scopes'] as $value) {
                $scope = \is_string($value) ? Scope::tryFrom($value) : null;
                if (null === $scope) {
                    $violations[] = \sprintf('Unknown scope "%s".', \is_scalar($value) ? (string) $value : \gettype($value));
                } elseif (null !== $manager && !$manager->hasScope($scope)) {
                    $violations[] = \sprintf('Your key does not hold "%s", so it cannot grant it.', $scope->value);
                } else {
                    $scopes[] = $scope;
                }
            }
        }

        $sections = [];
        if (isset($body['sections'])) {
            if (!\is_array($body['sections'])) {
                $violations[] = 'Field "sections" must be a list of section names.';
            } else {
                foreach ($body['sections'] as $value) {
                    if (!\is_string($value) || null === $this->sections->get($value)) {
                        $violations[] = \sprintf('Unknown section "%s".', \is_scalar($value) ? (string) $value : \gettype($value));
                    } elseif (null !== $manager && !$manager->allowsSection($value)) {
                        $violations[] = \sprintf('Your key cannot access section "%s", so it cannot grant it.', $value);
                    } else {
                        $sections[] = $value;
                    }
                }
            }
        }
        if (null !== $manager && [] !== $manager->getSections() && [] === $sections) {
            $violations[] = \sprintf('Your key is restricted to sections %s; the new key must name a subset in "sections".', implode(', ', $manager->getSections()));
        }

        $expiresAt = null;
        if (isset($body['expires_at'])) {
            try {
                $expiresAt = \is_string($body['expires_at']) ? new \DateTimeImmutable($body['expires_at']) : throw new \Exception();
            } catch (\Exception) {
                $violations[] = 'Field "expires_at" must be an ISO 8601 date-time.';
            }
            if (null !== $expiresAt && $expiresAt <= ($this->clock?->now() ?? new \DateTimeImmutable())) {
                $violations[] = 'Field "expires_at" must be in the future.';
            }
        }

        if ([] !== $violations) {
            throw new ValidationException($violations);
        }

        return [trim($name), array_values(array_unique($scopes, \SORT_REGULAR)), array_values(array_unique($sections)), $expiresAt];
    }
}
