<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Model;

final class ArticleFilter
{
    public const MAX_PER_PAGE = 100;
    public const SORTS = ['updated', 'published'];

    public function __construct(
        public readonly ?string $section = null,
        public readonly ?string $locale = null,
        public readonly ?ArticleState $state = null,
        public readonly int $page = 1,
        public readonly int $perPage = 20,
        /** "updated" (newest change first) or "published" (newest publication first, for public listings) */
        public readonly string $sort = 'updated',
    ) {
        if ($page < 1 || $perPage < 1 || $perPage > self::MAX_PER_PAGE) {
            throw new ValidationException([\sprintf('"page" must be >= 1 and "per_page" between 1 and %d.', self::MAX_PER_PAGE)]);
        }
        if (!\in_array($sort, self::SORTS, true)) {
            throw new ValidationException([\sprintf('"sort" must be one of: %s.', implode(', ', self::SORTS))]);
        }
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function matches(ArticleInterface $article): bool
    {
        return (null === $this->section || $article->getSection() === $this->section)
            && (null === $this->locale || $article->getLocale() === $this->locale)
            && (null === $this->state || $article->getState() === $this->state);
    }
}
