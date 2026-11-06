<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Bridge\Doctrine;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use MattLeads\AgentPublishingBundle\Analytics\VisitKind;
use MattLeads\AgentPublishingBundle\Contract\StatsStorageInterface;
use MattLeads\AgentPublishingBundle\Model\ReferrerStat;
use MattLeads\AgentPublishingBundle\Model\ViewStat;
use Symfony\Component\Uid\Ulid;

/**
 * Increments go through plain SQL so concurrent views never close the entity manager; reads use DQL aggregates.
 */
final class DoctrineStatsStorage implements StatsStorageInterface
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function recordView(string $articleId, string $section, \DateTimeImmutable $day, string $referrerHost, VisitKind $kind): void
    {
        $dayValue = $day->format('Y-m-d');
        $viewColumn = $kind->isHuman() ? 'views' : 'crawler_views';
        $other = $kind->isHuman() ? 'crawler_views' : 'views';

        $this->increment(
            "UPDATE agent_publishing_view_stat SET $viewColumn = $viewColumn + 1 WHERE article_id = ? AND day = ?",
            [$articleId, $dayValue],
            "INSERT INTO agent_publishing_view_stat (id, article_id, section, day, $viewColumn, $other) VALUES (?, ?, ?, ?, 1, 0)",
            [(string) new Ulid(), $articleId, $section, $dayValue],
        );
        $this->increment(
            'UPDATE agent_publishing_referrer_stat SET views = views + 1 WHERE article_id = ? AND day = ? AND host = ? AND kind = ?',
            [$articleId, $dayValue, $referrerHost, $kind->value],
            'INSERT INTO agent_publishing_referrer_stat (id, article_id, section, day, host, kind, views) VALUES (?, ?, ?, ?, ?, ?, 1)',
            [(string) new Ulid(), $articleId, $section, $dayValue, $referrerHost, $kind->value],
        );
    }

    public function viewsPerDay(string $articleId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<array{day: \DateTimeImmutable, views: int, crawlerViews: int}> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('s.day AS day', 's.views AS views', 's.crawlerViews AS crawlerViews')
            ->from(ViewStat::class, 's')
            ->andWhere('s.articleId = :article')->setParameter('article', $articleId)
            ->andWhere('s.day BETWEEN :from AND :to')->setParameter('from', $from->format('Y-m-d'))->setParameter('to', $to->format('Y-m-d'))
            ->orderBy('s.day', 'ASC')
            ->getQuery()->getArrayResult();

        return array_map(static fn(array $r) => ['day' => $r['day']->format('Y-m-d'), 'views' => $r['views'], 'crawler_views' => $r['crawlerViews']], $rows);
    }

    public function referrers(?string $articleId, ?string $section, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('r.host AS host', 'r.kind AS kind', 'SUM(r.views) AS views')
            ->from(ReferrerStat::class, 'r')
            ->andWhere('r.day BETWEEN :from AND :to')->setParameter('from', $from->format('Y-m-d'))->setParameter('to', $to->format('Y-m-d'))
            ->groupBy('r.host', 'r.kind')
            ->orderBy('views', 'DESC')->addOrderBy('r.host', 'ASC')
            ->setMaxResults($limit);
        if (null !== $articleId) {
            $qb->andWhere('r.articleId = :article')->setParameter('article', $articleId);
        }
        if (null !== $section) {
            $qb->andWhere('r.section = :section')->setParameter('section', $section);
        }
        /** @var list<array{host: string, kind: string, views: int|string}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return array_map(static fn(array $r) => ['host' => $r['host'], 'kind' => $r['kind'], 'views' => (int) $r['views']], $rows);
    }

    public function summary(?string $articleId, ?string $section, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(s.views), 0) AS views', 'COALESCE(SUM(s.crawlerViews), 0) AS crawlerViews')
            ->from(ViewStat::class, 's')
            ->andWhere('s.day BETWEEN :from AND :to')->setParameter('from', $from->format('Y-m-d'))->setParameter('to', $to->format('Y-m-d'));
        if (null !== $articleId) {
            $qb->andWhere('s.articleId = :article')->setParameter('article', $articleId);
        }
        if (null !== $section) {
            $qb->andWhere('s.section = :section')->setParameter('section', $section);
        }
        /** @var array{views: int|string, crawlerViews: int|string} $totals */
        $totals = $qb->getQuery()->getSingleResult();

        $byKind = [];
        foreach ($this->referrers($articleId, $section, $from, $to, \PHP_INT_MAX) as $row) {
            $byKind[$row['kind']] = ($byKind[$row['kind']] ?? 0) + $row['views'];
        }
        ksort($byKind);

        return ['views' => (int) $totals['views'], 'crawler_views' => (int) $totals['crawlerViews'], 'by_kind' => $byKind];
    }

    /**
     * @param list<string> $updateParams
     * @param list<string> $insertParams
     */
    private function increment(string $update, array $updateParams, string $insert, array $insertParams): void
    {
        $connection = $this->em->getConnection();
        if (0 < $connection->executeStatement($update, $updateParams)) {
            return;
        }
        try {
            $connection->executeStatement($insert, $insertParams);
        } catch (UniqueConstraintViolationException) {
            // Another request inserted the row first; count on it instead.
            $connection->executeStatement($update, $updateParams);
        }
    }
}
