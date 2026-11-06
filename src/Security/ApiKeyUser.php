<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Security;

use MattLeads\AgentPublishingBundle\Model\ApiKey;
use MattLeads\AgentPublishingBundle\Model\Scope;
use Symfony\Component\Security\Core\User\UserInterface;

final class ApiKeyUser implements UserInterface
{
    public const ROLE = 'ROLE_AGENT_PUBLISHING';

    public function __construct(private readonly ApiKey $key) {}

    public function getApiKey(): ApiKey
    {
        return $this->key;
    }

    public function getRoles(): array
    {
        return [self::ROLE, ...array_map(static fn(Scope $s) => $s->role(), $this->key->getScopes())];
    }

    public function getUserIdentifier(): string
    {
        return 'apikey:' . $this->key->getId();
    }

    #[\Deprecated]
    public function eraseCredentials(): void {}
}
