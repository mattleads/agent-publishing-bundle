<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Model;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

/**
 * A slug a published article used to have. The host redirects it to the article's current URL.
 */
#[ORM\Entity]
#[ORM\Table(name: 'agent_publishing_slug_redirect')]
#[ORM\UniqueConstraint(name: 'uniq_agent_publishing_former_slug', columns: ['section', 'locale', 'slug'])]
#[ORM\Index(name: 'idx_agent_publishing_former_slug_article', columns: ['article_id'])]
class SlugRedirect
{
    #[ORM\Id]
    #[ORM\Column(length: 26)]
    private string $id;

    public function __construct(
        #[ORM\Column(length: 64)]
        private string $section,
        #[ORM\Column(length: 8)]
        private string $locale,
        #[ORM\Column(length: 200)]
        private string $slug,
        #[ORM\Column(name: 'article_id', length: 26)]
        private string $articleId,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->id = (string) new Ulid();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSection(): string
    {
        return $this->section;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getArticleId(): string
    {
        return $this->articleId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
