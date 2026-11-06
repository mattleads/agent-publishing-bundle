<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Tests\Functional;

use MattLeads\AgentPublishingBundle\Contract\ArticleStorageInterface;
use MattLeads\AgentPublishingBundle\Model\ArticleFilter;
use MattLeads\AgentPublishingBundle\Model\ArticleInput;
use MattLeads\AgentPublishingBundle\Model\ArticleInterface;
use MattLeads\AgentPublishingBundle\Model\ArticleState;
use MattLeads\AgentPublishingBundle\Model\ValidationException;

/** A host decorator that vetoes publishing, like one that cannot fetch an article's images. */
final class RefusingArticleStorage implements ArticleStorageInterface
{
    public function __construct(private readonly ArticleStorageInterface $inner) {}

    public function find(string $id): ?ArticleInterface
    {
        return $this->inner->find($id);
    }

    public function list(ArticleFilter $filter): array
    {
        return $this->inner->list($filter);
    }

    public function count(ArticleFilter $filter): int
    {
        return $this->inner->count($filter);
    }

    public function slugExists(string $section, string $locale, string $slug, ?string $exceptId = null): bool
    {
        return $this->inner->slugExists($section, $locale, $slug, $exceptId);
    }

    public function findBySlug(string $section, string $locale, string $slug): ?ArticleInterface
    {
        return $this->inner->findBySlug($section, $locale, $slug);
    }

    public function findByFormerSlug(string $section, string $locale, string $slug): ?ArticleInterface
    {
        return $this->inner->findByFormerSlug($section, $locale, $slug);
    }

    public function formerSlugs(ArticleInterface $article): array
    {
        return $this->inner->formerSlugs($article);
    }

    public function create(ArticleInput $input, string $slug, ?string $createdBy, \DateTimeImmutable $now): ArticleInterface
    {
        return $this->inner->create($input, $slug, $createdBy, $now);
    }

    public function update(ArticleInterface $article, ArticleInput $input, ?string $slug, \DateTimeImmutable $now): ArticleInterface
    {
        return $this->inner->update($article, $input, $slug, $now);
    }

    public function transition(ArticleInterface $article, ArticleState $to, \DateTimeImmutable $now, ?string $note = null, ?\DateTimeImmutable $publishAt = null): ArticleInterface
    {
        if (ArticleState::Published === $to) {
            throw new ValidationException(['Image https://example.org/x.png: not found.']);
        }

        return $this->inner->transition($article, $to, $now, $note, $publishAt);
    }
}
