<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Api;

use MattLeads\AgentPublishingBundle\Model\ApiKey;
use MattLeads\AgentPublishingBundle\Model\Scope;

/** Never includes the secret or its hash. */
final class ApiKeyNormalizer
{
    /** @return array<string, mixed> */
    public function normalize(ApiKey $key): array
    {
        return [
            'id' => $key->getId(),
            'name' => $key->getName(),
            'scopes' => array_map(static fn(Scope $s) => $s->value, $key->getScopes()),
            'sections' => $key->getSections(),
            'created_at' => $key->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'last_used_at' => $key->getLastUsedAt()?->format(\DateTimeInterface::ATOM),
            'expires_at' => $key->getExpiresAt()?->format(\DateTimeInterface::ATOM),
            'revoked_at' => $key->getRevokedAt()?->format(\DateTimeInterface::ATOM),
            'active' => !$key->isRevoked() && !$key->isExpired(new \DateTimeImmutable()),
        ];
    }
}
