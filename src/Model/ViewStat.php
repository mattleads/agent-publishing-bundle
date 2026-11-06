<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Model;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

/**
 * One row per article and day. "views" counts humans, "crawlerViews" counts AI crawlers and other bots.
 */
#[ORM\Entity]
#[ORM\Table(name: 'agent_publishing_view_stat')]
#[ORM\UniqueConstraint(name: 'uniq_agent_publishing_view', columns: ['article_id', 'day'])]
#[ORM\Index(name: 'idx_agent_publishing_view_section_day', columns: ['section', 'day'])]
class ViewStat
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

    #[ORM\Column]
    private int $views = 0;

    #[ORM\Column(name: 'crawler_views')]
    private int $crawlerViews = 0;

    public function __construct(string $articleId, string $section, \DateTimeImmutable $day)
    {
        $this->id = (string) new Ulid();
        $this->articleId = $articleId;
        $this->section = $section;
        $this->day = $day;
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

    public function getViews(): int
    {
        return $this->views;
    }

    public function getCrawlerViews(): int
    {
        return $this->crawlerViews;
    }

    public function increment(bool $human): void
    {
        if ($human) {
            ++$this->views;
        } else {
            ++$this->crawlerViews;
        }
    }
}
