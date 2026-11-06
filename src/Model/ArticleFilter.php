<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Model;

final class ArticleFilter
{
    public const MAX_PER_PAGE = 100;

    public function __construct(
        public readonly ?string $section = null,
        public readonly ?string $locale = null,
        public readonly ?ArticleState $state = null,
        public readonly int $page = 1,
        public readonly int $perPage = 20,
    ) {
        if ($page < 1 || $perPage < 1 || $perPage > self::MAX_PER_PAGE) {
            throw new ValidationException([\sprintf('"page" must be >= 1 and "per_page" between 1 and %d.', self::MAX_PER_PAGE)]);
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
