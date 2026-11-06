<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Storage;

use MattLeads\AgentPublishingBundle\Analytics\VisitKind;
use MattLeads\AgentPublishingBundle\Contract\StatsStorageInterface;
use MattLeads\AgentPublishingBundle\Model\ReferrerStat;
use MattLeads\AgentPublishingBundle\Model\ViewStat;

final class InMemoryStatsStorage implements StatsStorageInterface
{
    /** @var array<string, ViewStat> */
    private array $views = [];
    /** @var array<string, ReferrerStat> */
    private array $referrers = [];

    public function recordView(string $articleId, string $section, \DateTimeImmutable $day, string $referrerHost, VisitKind $kind): void
    {
        $dayKey = $day->format('Y-m-d');
        ($this->views[$articleId . '|' . $dayKey] ??= new ViewStat($articleId, $section, $day))->increment($kind->isHuman());
        ($this->referrers[$articleId . '|' . $dayKey . '|' . $referrerHost . '|' . $kind->value] ??= new ReferrerStat($articleId, $section, $day, $referrerHost, $kind->value))->increment();
    }

    public function viewsPerDay(string $articleId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = [];
        foreach ($this->views as $stat) {
            if ($stat->getArticleId() === $articleId && self::within($stat->getDay(), $from, $to)) {
                $rows[] = ['day' => $stat->getDay()->format('Y-m-d'), 'views' => $stat->getViews(), 'crawler_views' => $stat->getCrawlerViews()];
            }
        }
        usort($rows, static fn(array $a, array $b) => strcmp($a['day'], $b['day']));

        return $rows;
    }

    public function referrers(?string $articleId, ?string $section, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit): array
    {
        /** @var array<string, array{host: string, kind: string, views: int}> $rows */
        $rows = [];
        foreach ($this->referrers as $stat) {
            if ((null === $articleId || $stat->getArticleId() === $articleId) && (null === $section || $stat->getSection() === $section) && self::within($stat->getDay(), $from, $to)) {
                $key = $stat->getHost() . '|' . $stat->getKind();
                $rows[$key] ??= ['host' => $stat->getHost(), 'kind' => $stat->getKind(), 'views' => 0];
                $rows[$key]['views'] += $stat->getViews();
            }
        }
        $rows = array_values($rows);
        usort($rows, static fn(array $a, array $b) => $b['views'] <=> $a['views'] ?: strcmp($a['host'], $b['host']));

        return \array_slice($rows, 0, $limit);
    }

    public function summary(?string $articleId, ?string $section, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $views = $crawler = 0;
        foreach ($this->views as $stat) {
            if ((null === $articleId || $stat->getArticleId() === $articleId) && (null === $section || $stat->getSection() === $section) && self::within($stat->getDay(), $from, $to)) {
                $views += $stat->getViews();
                $crawler += $stat->getCrawlerViews();
            }
        }
        $byKind = [];
        foreach ($this->referrers($articleId, $section, $from, $to, \PHP_INT_MAX) as $row) {
            $byKind[$row['kind']] = ($byKind[$row['kind']] ?? 0) + $row['views'];
        }
        ksort($byKind);

        return ['views' => $views, 'crawler_views' => $crawler, 'by_kind' => $byKind];
    }

    private static function within(\DateTimeImmutable $day, \DateTimeImmutable $from, \DateTimeImmutable $to): bool
    {
        $d = $day->format('Y-m-d');

        return $d >= $from->format('Y-m-d') && $d <= $to->format('Y-m-d');
    }
}
