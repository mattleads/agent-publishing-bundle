<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Model;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

/**
 * One row per article, day, referrer host and kind. The host is "" for direct and crawler views.
 */
#[ORM\Entity]
#[ORM\Table(name: 'agent_publishing_referrer_stat')]
#[ORM\UniqueConstraint(name: 'uniq_agent_publishing_referrer', columns: ['article_id', 'day', 'host', 'kind'])]
#[ORM\Index(name: 'idx_agent_publishing_referrer_section_day', columns: ['section', 'day'])]
class ReferrerStat
{
    #[ORM\Id]
    #[ORM\Column(length: 26)]
    private string $id;

    #[ORM\Column(name: 'article_id', length: 26)]
    private string $articleId;

    #[ORM\Column(length: 64)]
    private string $section;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $day;

    #[ORM\Column(length: 255)]
    private string $host;

    #[ORM\Column(length: 16)]
    private string $kind;

    #[ORM\Column]
    private int $views = 0;

    public function __construct(string $articleId, string $section, \DateTimeImmutable $day, string $host, string $kind)
    {
        $this->id = (string) new Ulid();
        $this->articleId = $articleId;
        $this->section = $section;
        $this->day = $day;
        $this->host = $host;
        $this->kind = $kind;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getArticleId(): string
    {
        return $this->articleId;
    }

    public function getSection(): string
    {
        return $this->section;
    }

    public function getDay(): \DateTimeImmutable
    {
        return $this->day;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getViews(): int
    {
        return $this->views;
    }

    public function increment(): void
    {
        ++$this->views;
    }
}
