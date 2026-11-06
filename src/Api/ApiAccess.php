<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Api;

use MattLeads\AgentPublishingBundle\Model\ApiKey;
use MattLeads\AgentPublishingBundle\Model\Scope;
use MattLeads\AgentPublishingBundle\Security\ApiKeyUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Scope and section checks for controllers, answering with JSON problems instead of HTML error pages.
 */
final class ApiAccess
{
    public function __construct(private readonly TokenStorageInterface $tokens) {}

    public function key(): ?ApiKey
    {
        $user = $this->tokens->getToken()?->getUser();

        return $user instanceof ApiKeyUser ? $user->getApiKey() : null;
    }

    /** Returns a 401/403 response when the current key lacks the scope or the section, null when allowed. */
    public function deny(Scope $scope, ?string $section = null): ?JsonResponse
    {
        $key = $this->key();
        if (null === $key) {
            return Problem::json(Response::HTTP_UNAUTHORIZED, 'API key required.');
        }
        if (!$key->hasScope($scope)) {
            return Problem::json(Response::HTTP_FORBIDDEN, \sprintf('This key lacks the "%s" scope.', $scope->value));
        }
        if (null !== $section && !$key->allowsSection($section)) {
            return Problem::json(Response::HTTP_FORBIDDEN, \sprintf('This key cannot access section "%s".', $section));
        }

        return null;
    }
}
