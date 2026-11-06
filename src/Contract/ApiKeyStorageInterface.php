<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Contract;

use MattLeads\AgentPublishingBundle\Model\ApiKey;

interface ApiKeyStorageInterface
{
    public function save(ApiKey $key): void;

    public function findByHash(string $hash): ?ApiKey;

    public function findById(string $id): ?ApiKey;

    /** @return list<ApiKey> */
    public function all(): array;
}
