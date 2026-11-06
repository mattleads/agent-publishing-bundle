<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Api;

use MattLeads\AgentPublishingBundle\Author\AuthorRegistry;
use MattLeads\AgentPublishingBundle\Model\Author;
use MattLeads\AgentPublishingBundle\Model\Scope;
use Symfony\Component\HttpFoundation\JsonResponse;

final class AuthorController
{
    public function __construct(private readonly AuthorRegistry $authors, private readonly ApiAccess $access) {}

    public function list(): JsonResponse
    {
        if ($denied = $this->access->deny(Scope::ArticlesRead)) {
            return $denied;
        }

        return new JsonResponse(self::payload($this->authors));
    }

    /** @return array{items: list<array<string, mixed>>, default: string} */
    public static function payload(AuthorRegistry $authors): array
    {
        return ['items' => array_map(static fn(Author $a) => $a->toArray(), $authors->all()), 'default' => $authors->defaultAuthor()];
    }
}
