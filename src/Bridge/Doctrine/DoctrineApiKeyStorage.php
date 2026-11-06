<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Bridge\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use MattLeads\AgentPublishingBundle\Contract\ApiKeyStorageInterface;
use MattLeads\AgentPublishingBundle\Model\ApiKey;

final class DoctrineApiKeyStorage implements ApiKeyStorageInterface
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function save(ApiKey $key): void
    {
        $this->em->persist($key);
        $this->em->flush();
    }

    public function findByHash(string $hash): ?ApiKey
    {
        return $this->em->getRepository(ApiKey::class)->findOneBy(['hash' => $hash]);
    }

    public function findById(string $id): ?ApiKey
    {
        return $this->em->find(ApiKey::class, $id);
    }

    public function all(): array
    {
        return $this->em->getRepository(ApiKey::class)->findBy([], ['createdAt' => 'ASC']);
    }
}
