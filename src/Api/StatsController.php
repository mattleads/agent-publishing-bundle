<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Api;

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
    public const DEFAULT_DAYS = 30;
    public const MAX_DAYS = 366;
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
        $today = ($this->clock?->now() ?? new \DateTimeImmutable())->setTimezone($this->timezone)->setTime(0, 0);
        $to = $this->day($request, 'to') ?? $today;
        $from = $this->day($request, 'from') ?? $to->modify(\sprintf('-%d days', self::DEFAULT_DAYS - 1));
        if ($from > $to) {
            throw new ValidationException(['"from" must not be after "to".']);
        }
        if ($from->diff($to)->days >= self::MAX_DAYS) {
            throw new ValidationException([\sprintf('The period must not exceed %d days.', self::MAX_DAYS)]);
        }

        return [$from, $to];
    }

    /** @throws ValidationException */
    private function day(Request $request, string $name): ?\DateTimeImmutable
    {
        $value = $request->query->get($name);
        if (null === $value || '' === $value) {
            return null;
        }
        if (!\is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || false === $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $this->timezone)) {
            throw new ValidationException([\sprintf('"%s" must be a date formatted YYYY-MM-DD.', $name)]);
        }

        return $day;
    }

    private function invalid(ValidationException $e): JsonResponse
    {
        return Problem::json(Response::HTTP_UNPROCESSABLE_ENTITY, 'Invalid query.', ['violations' => $e->violations]);
    }
}
