<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Model;

/**
 * Read-only view of an article. Mutation goes through ArticleStorageInterface so a host application can map this
 * onto its own entity without exposing setters to the bundle.
 */
interface ArticleInterface
{
    public function getId(): string;

    public function getSection(): string;

    public function getLocale(): string;

    public function getTitle(): string;

    public function getSlug(): string;

    public function getSummary(): ?string;

    public function getBody(): string;

    /** "markdown" or "html". */
    public function getFormat(): string;

    /** @return list<string> */
    public function getTags(): array;

    /** Id shared by the translations of one article, or null. */
    public function getTranslationGroup(): ?string;

    public function getState(): ArticleState;

    public function getCreatedAt(): \DateTimeImmutable;

    public function getUpdatedAt(): \DateTimeImmutable;

    public function getPublishedAt(): ?\DateTimeImmutable;

    /** Scheduled publication time, set while the state is "scheduled". */
    public function getPublishAt(): ?\DateTimeImmutable;

    /** Reason given on the last reject, or null. */
    public function getReviewNote(): ?string;

    /** Name of the API key that created the article, or null. */
    public function getCreatedBy(): ?string;
}
