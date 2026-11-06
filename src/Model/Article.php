<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Model;

use Doctrine\ORM\Mapping as ORM;

/**
 * Bundled article model. Used by the in-memory storage and, through the attribute mapping, by the Doctrine storage.
 */
#[ORM\Entity]
#[ORM\Table(name: 'agent_publishing_article')]
#[ORM\UniqueConstraint(name: 'uniq_agent_publishing_slug', columns: ['section', 'locale', 'slug'])]
#[ORM\Index(name: 'idx_agent_publishing_state', columns: ['state'])]
class Article implements ArticleInterface
{
    #[ORM\Id]
    #[ORM\Column(length: 26)]
    private string $id;

    #[ORM\Column(length: 64)]
    private string $section;

    #[ORM\Column(length: 8)]
    private string $locale;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column(length: 200)]
    private string $slug;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $summary = null;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column(length: 16)]
    private string $format;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $tags = [];

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $translationGroup = null;

    #[ORM\Column(length: 16, enumType: ArticleState::class)]
    private ArticleState $state = ArticleState::Draft;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reviewNote = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $createdBy = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $metaTitle = null;

    #[ORM\Column(length: 320, nullable: true)]
    private ?string $metaDescription = null;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $coverImage = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $coverImageAlt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $author = null;

    public function __construct(
        string $id,
        string $section,
        string $locale,
        string $title,
        string $slug,
        string $body,
        string $format,
        \DateTimeImmutable $now,
        ?string $createdBy = null,
    ) {
        $this->id = $id;
        $this->section = $section;
        $this->locale = $locale;
        $this->title = $title;
        $this->slug = $slug;
        $this->body = $body;
        $this->format = $format;
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->createdBy = $createdBy;
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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function getTranslationGroup(): ?string
    {
        return $this->translationGroup;
    }

    public function getState(): ArticleState
    {
        return $this->state;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getPublishAt(): ?\DateTimeImmutable
    {
        return $this->publishAt;
    }

    public function getReviewNote(): ?string
    {
        return $this->reviewNote;
    }

    public function getCreatedBy(): ?string
    {
        return $this->createdBy;
    }

    public function getMetaTitle(): ?string
    {
        return $this->metaTitle;
    }

    public function getMetaDescription(): ?string
    {
        return $this->metaDescription;
    }

    public function getCoverImage(): ?string
    {
        return $this->coverImage;
    }

    public function getCoverImageAlt(): ?string
    {
        return $this->coverImageAlt;
    }

    public function getAuthor(): ?string
    {
        return $this->author;
    }

    /** Applies the provided (non-null) fields of an input and stamps updatedAt. */
    public function apply(ArticleInput $input, ?string $slug, \DateTimeImmutable $now): void
    {
        if (null !== $input->title) {
            $this->title = $input->title;
        }
        if (null !== $slug) {
            $this->slug = $slug;
        }
        if (null !== $input->summary) {
            $this->summary = '' === $input->summary ? null : $input->summary;
        }
        if (null !== $input->body) {
            $this->body = $input->body;
        }
        if (null !== $input->format) {
            $this->format = $input->format;
        }
        if (null !== $input->tags) {
            $this->tags = $input->tags;
        }
        if (null !== $input->translationGroup) {
            $this->translationGroup = '' === $input->translationGroup ? null : $input->translationGroup;
        }
        // An empty string clears an optional field, as for summary and translation_group.
        if (null !== $input->metaTitle) {
            $this->metaTitle = '' === $input->metaTitle ? null : $input->metaTitle;
        }
        if (null !== $input->metaDescription) {
            $this->metaDescription = '' === $input->metaDescription ? null : $input->metaDescription;
        }
        if (null !== $input->coverImage) {
            $this->coverImage = '' === $input->coverImage ? null : $input->coverImage;
        }
        if (null !== $input->coverImageAlt) {
            $this->coverImageAlt = '' === $input->coverImageAlt ? null : $input->coverImageAlt;
        }
        if (null !== $input->author) {
            $this->author = '' === $input->author ? null : $input->author;
        }
        $this->updatedAt = $now;
    }

    public function transitionTo(ArticleState $state, \DateTimeImmutable $now, ?string $note, ?\DateTimeImmutable $publishAt): void
    {
        $this->state = $state;
        $this->updatedAt = $now;
        $this->reviewNote = $note;
        $this->publishAt = ArticleState::Scheduled === $state ? $publishAt : null;
        if (ArticleState::Published === $state) {
            $this->publishedAt = $now;
        }
    }
}
