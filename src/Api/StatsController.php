<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Api;

use MattLeads\AgentPublishingBundle\Analytics\StatsPeriod;
use MattLeads\AgentPublishingBundle\Contract\ArticleStorageInterface;
use MattLeads\AgentPublishingBundle\Contract\StatsStorageInterface;
use MattLeads\AgentPublishingBundle\Model\Scope;
use MattLeads\AgentPublishingBundle\Model\ValidationException;
use MattLeads\AgentPublishingBundle\Section\SectionRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class StatsController
{
    public const MAX_REFERRERS = 200;

    public function __construct(
        private readonly StatsStorageInterface $stats,
        private readonly ArticleStorageInterface $articles,
        private readonly SectionRegistry $sections,
        private readonly ApiAccess $access,
        private readonly \DateTimeZone $timezone,
        private readonly ?ClockInterface $clock = null,
    ) {}

    public function article(string $id, Request $request): JsonResponse
    {
        $article = $this->articles->find($id);
        if (null === $article) {
            return Problem::json(Response::HTTP_NOT_FOUND, 'Article not found.');
        }
        if ($denied = $this->access->denyForArticle(Scope::StatsRead, $article)) {
            return $denied;
        }
        try {
            [$from, $to] = $this->period($request);
        } catch (ValidationException $e) {
            return $this->invalid($e);
        }

        return new JsonResponse([
            'article_id' => $article->getId(),
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ] + $this->stats->summary($article->getId(), null, $from, $to) + [
            'per_day' => $this->stats->viewsPerDay($article->getId(), $from, $to),
            'referrers' => $this->stats->referrers($article->getId(), null, $from, $to, self::MAX_REFERRERS),
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        try {
            $section = $this->section($request);
            if ($denied = $this->access->deny(Scope::StatsRead, $section)) {
                return $denied;
            }
            [$from, $to] = $this->period($request);
        } catch (ValidationException $e) {
            return $this->invalid($e);
        }

        return new JsonResponse([
            'section' => $section,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ] + $this->stats->summary(null, $section, $from, $to));
    }

    public function referrers(Request $request): JsonResponse
    {
        try {
            $section = $this->section($request);
            if ($denied = $this->access->deny(Scope::StatsRead, $section)) {
                return $denied;
            }
            [$from, $to] = $this->period($request);
            $limit = $request->query->getInt('limit', 50);
            if ($limit < 1 || $limit > self::MAX_REFERRERS) {
                throw new ValidationException([\sprintf('"limit" must be between 1 and %d.', self::MAX_REFERRERS)]);
            }
        } catch (ValidationException $e) {
            return $this->invalid($e);
        }

        return new JsonResponse([
            'section' => $section,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'items' => $this->stats->referrers(null, $section, $from, $to, $limit),
        ]);
    }

    /** @throws ValidationException */
    private function section(Request $request): ?string
    {
        $section = $request->query->getString('section');
        $section = $this->access->listingSection('' === $section ? null : $section);
        if (null === $section) {
            return null;
        }
        if (null === $this->sections->get($section)) {
            throw new ValidationException([\sprintf('Unknown section "%s".', $section)]);
        }

        return $section;
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} inclusive days in the analytics timezone
     *
     * @throws ValidationException
     */
    private function period(Request $request): array
    {
        $period = StatsPeriod::parse($request->query->getString('from'), $request->query->getString('to'), $this->timezone, $this->clock?->now() ?? new \DateTimeImmutable());

        return [$period->from, $period->to];
    }

    private function invalid(ValidationException $e): JsonResponse
    {
        return Problem::json(Response::HTTP_UNPROCESSABLE_ENTITY, 'Invalid query.', ['violations' => $e->violations]);
    }
}
