<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Security;

use MattLeads\AgentPublishingBundle\Contract\ApiKeyStorageInterface;
use MattLeads\AgentPublishingBundle\Model\ApiKey;
use MattLeads\AgentPublishingBundle\Model\Scope;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Ulid;

final class ApiKeyManager
{
    public const PREFIX = 'apb_';

    public function __construct(
        private readonly ApiKeyStorageInterface $storage,
        #[\SensitiveParameter]
        private readonly string $pepper,
        private readonly ?ClockInterface $clock = null,
    ) {
        if ('' === $pepper) {
            throw new \LogicException('API keys are hashed with kernel.secret; set APP_SECRET to a non-empty value.');
        }
    }

    /**
     * @param list<Scope>  $scopes
     * @param list<string> $sections empty for every section
     *
     * @return array{key: ApiKey, secret: string} the secret is shown once and never stored
     */
    public function issue(string $name, array $scopes, array $sections = [], ?\DateTimeImmutable $expiresAt = null): array
    {
        $secret = self::PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $key = new ApiKey((string) new Ulid(), $name, $this->hash($secret), $scopes, $sections, $this->now(), $expiresAt);
        $this->storage->save($key);

        return ['key' => $key, 'secret' => $secret];
    }

    /** Returns the active key for a secret, stamping its last use, or null. */
    public function resolve(#[\SensitiveParameter] string $secret): ?ApiKey
    {
        if (!str_starts_with($secret, self::PREFIX)) {
            return null;
        }
        $key = $this->storage->findByHash($this->hash($secret));
        if (null === $key || $key->isRevoked() || $key->isExpired($this->now())) {
            return null;
        }
        $key->markUsed($this->now());
        $this->storage->save($key);

        return $key;
    }

    public function revoke(ApiKey $key): void
    {
        $key->revoke($this->now());
        $this->storage->save($key);
    }

    private function hash(#[\SensitiveParameter] string $secret): string
    {
        return hash_hmac('sha256', $secret, $this->pepper);
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock?->now() ?? new \DateTimeImmutable();
    }
}
