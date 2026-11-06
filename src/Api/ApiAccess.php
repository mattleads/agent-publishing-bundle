<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Api;

use MattLeads\AgentPublishingBundle\Model\ApiKey;
use MattLeads\AgentPublishingBundle\Model\ArticleInterface;
use MattLeads\AgentPublishingBundle\Model\Scope;
use MattLeads\AgentPublishingBundle\Model\ValidationException;
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

    /**
     * Like deny(), for an article the key may not see: answers 404 so a section-restricted key cannot probe for
     * articles in other sections.
     */
    public function denyForArticle(Scope $scope, ArticleInterface $article): ?JsonResponse
    {
        $key = $this->key();
        if (null !== $key && !$key->allowsSection($article->getSection())) {
            return Problem::json(Response::HTTP_NOT_FOUND, 'Article not found.');
        }

        return $this->deny($scope);
    }

    /**
     * The section a listing or aggregate applies to: the requested one, else the key's only allowed section, else null
     * for everything. A key restricted to several sections has to name one, so totals never include sections it
     * cannot read.
     *
     * @throws ValidationException
     */
    public function listingSection(?string $requested): ?string
    {
        $key = $this->key();
        if (null !== $requested || null === $key || [] === $key->getSections()) {
            return $requested;
        }
        $sections = $key->getSections();
        if (1 === \count($sections)) {
            return $sections[0];
        }

        throw new ValidationException([\sprintf('This key is restricted to sections %s; pass "section" to choose one.', implode(', ', $sections))]);
    }
}
