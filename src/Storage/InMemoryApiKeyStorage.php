<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Storage;

use MattLeads\AgentPublishingBundle\Contract\ApiKeyStorageInterface;
use MattLeads\AgentPublishingBundle\Model\ApiKey;

final class InMemoryApiKeyStorage implements ApiKeyStorageInterface
{
    /** @var array<string, ApiKey> */
    private array $keys = [];

    public function save(ApiKey $key): void
    {
        $this->keys[$key->getId()] = $key;
    }

    public function findByHash(string $hash): ?ApiKey
    {
        foreach ($this->keys as $key) {
            if (hash_equals($key->getHash(), $hash)) {
                return $key;
            }
        }

        return null;
    }

    public function findById(string $id): ?ApiKey
    {
        return $this->keys[$id] ?? null;
    }

    public function all(): array
    {
        return array_values($this->keys);
    }
}
