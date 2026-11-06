<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Model;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'agent_publishing_api_key')]
class ApiKey
{
    #[ORM\Id]
    #[ORM\Column(length: 26)]
    private string $id;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 64, unique: true)]
    private string $hash;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $scopes;

    /** @var list<string> Empty means every section. */
    #[ORM\Column(type: 'json')]
    private array $sections;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    /**
     * @param list<Scope>  $scopes
     * @param list<string> $sections
     */
    public function __construct(string $id, string $name, string $hash, array $scopes, array $sections, \DateTimeImmutable $now, ?\DateTimeImmutable $expiresAt = null)
    {
        $this->expiresAt = $expiresAt;
        $this->id = $id;
        $this->name = $name;
        $this->hash = $hash;
        $this->scopes = array_values(array_unique(array_map(static fn(Scope $s) => $s->value, $scopes)));
        $this->sections = array_values(array_unique($sections));
        $this->createdAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    /** @return list<Scope> */
    public function getScopes(): array
    {
        return array_values(array_filter(array_map(static fn(string $v) => Scope::tryFrom($v), $this->scopes)));
    }

    /** @return list<string> */
    public function getSections(): array
    {
        return $this->sections;
    }

    public function hasScope(Scope $scope): bool
    {
        return \in_array($scope->value, $this->scopes, true);
    }

    public function allowsSection(string $section): bool
    {
        return [] === $this->sections || \in_array($section, $this->sections, true);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return null !== $this->expiresAt && $this->expiresAt <= $now;
    }

    /** Whether this key may act on $other: an unrestricted key manages everything, a restricted one only keys inside its sections. */
    public function manages(self $other): bool
    {
        if ([] === $this->sections) {
            return true;
        }

        return [] !== $other->sections && [] === array_diff($other->sections, $this->sections);
    }

    public function markUsed(\DateTimeImmutable $now): void
    {
        $this->lastUsedAt = $now;
    }

    public function revoke(\DateTimeImmutable $now): void
    {
        $this->revokedAt ??= $now;
    }
}
