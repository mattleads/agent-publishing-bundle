<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Contract;

use MattLeads\AgentPublishingBundle\Model\ArticleFilter;
use MattLeads\AgentPublishingBundle\Model\ArticleInput;
use MattLeads\AgentPublishingBundle\Model\ArticleInterface;
use MattLeads\AgentPublishingBundle\Model\ArticleState;

/**
 * Implement this to keep articles in your own entities. The bundle validates input, resolves slugs and checks
 * workflow rules before calling these methods, so an implementation only has to persist what it is given.
 */
interface ArticleStorageInterface
{
    public function find(string $id): ?ArticleInterface;

    /** @return list<ArticleInterface> newest updated first */
    public function list(ArticleFilter $filter): array;

    public function count(ArticleFilter $filter): int;

    public function slugExists(string $section, string $locale, string $slug, ?string $exceptId = null): bool;

    public function findBySlug(string $section, string $locale, string $slug): ?ArticleInterface;

    /**
     * The article that used to have this slug, for a permanent redirect to its current URL.
     * A former slug is recorded when a published or scheduled article is renamed, and forgotten
     * as soon as any article (the same one included) takes that slug again.
     */
    public function findByFormerSlug(string $section, string $locale, string $slug): ?ArticleInterface;

    /** @return list<string> former slugs of the article, oldest first */
    public function formerSlugs(ArticleInterface $article): array;

    /** $input->section, locale, title and body are guaranteed non-null. */
    public function create(ArticleInput $input, string $slug, ?string $createdBy, \DateTimeImmutable $now): ArticleInterface;

    /** Only non-null fields of $input change. $slug is null when the slug stays as it is. */
    public function update(ArticleInterface $article, ArticleInput $input, ?string $slug, \DateTimeImmutable $now): ArticleInterface;

    /** $publishAt is set for the "scheduled" state only. $note is the reject reason, or null. */
    public function transition(ArticleInterface $article, ArticleState $to, \DateTimeImmutable $now, ?string $note = null, ?\DateTimeImmutable $publishAt = null): ArticleInterface;
}
