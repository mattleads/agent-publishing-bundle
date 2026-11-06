<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Analytics;

/**
 * Messenger message for one page view. Carries only what the rollups need: no IP, no user agent, no raw URL.
 */
final class ArticleViewed
{
    public function __construct(
        public readonly string $articleId,
        public readonly string $section,
        public readonly \DateTimeImmutable $viewedAt,
        public readonly VisitKind $kind,
        public readonly string $referrerHost,
    ) {}
}
