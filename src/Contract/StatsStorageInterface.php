<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Contract;

use MattLeads\AgentPublishingBundle\Analytics\VisitKind;

/**
 * Daily rollups of views per article and per referrer. Implement it to keep counts elsewhere; the bundled Doctrine
 * and in-memory storages are the reference. $from and $to are inclusive calendar days.
 */
interface StatsStorageInterface
{
    /** Adds one view to the article's day and to the (article, referrer host, kind) row of that day. $referrerHost is "" for direct and crawler views. */
    public function recordView(string $articleId, string $section, \DateTimeImmutable $day, string $referrerHost, VisitKind $kind): void;

    /** @return list<array{day: string, views: int, crawler_views: int}> ascending by day, days without views omitted */
    public function viewsPerDay(string $articleId, \DateTimeImmutable $from, \DateTimeImmutable $to): array;

    /** @return list<array{host: string, kind: string, views: int}> descending by views; pass null to aggregate over all articles or sections */
    public function referrers(?string $articleId, ?string $section, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit): array;

    /** @return array{views: int, crawler_views: int, by_kind: array<string, int>} */
    public function summary(?string $articleId, ?string $section, \DateTimeImmutable $from, \DateTimeImmutable $to): array;
}
