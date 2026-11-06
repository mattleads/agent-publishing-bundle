<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Mcp\Tool;

use MattLeads\AgentPublishingBundle\Analytics\StatsPeriod;
use MattLeads\AgentPublishingBundle\Contract\ArticleStorageInterface;
use MattLeads\AgentPublishingBundle\Contract\StatsStorageInterface;
use MattLeads\AgentPublishingBundle\Mcp\ToolException;
use MattLeads\AgentPublishingBundle\Model\ApiKey;
use MattLeads\AgentPublishingBundle\Model\Scope;
use MattLeads\AgentPublishingBundle\Model\ValidationException;
use MattLeads\AgentPublishingBundle\Section\SectionRegistry;
use Psr\Clock\ClockInterface;

/** Three instances: get_article_stats, get_stats_summary, get_top_referrers. */
final class StatsTool extends AbstractTool
{
    public const KINDS = ['article', 'summary', 'referrers'];

    public function __construct(
        private readonly string $kind,
        private readonly StatsStorageInterface $stats,
        private readonly ArticleStorageInterface $articles,
        private readonly SectionRegistry $sections,
        private readonly \DateTimeZone $timezone,
        private readonly ?ClockInterface $clock = null,
    ) {
        if (!\in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown stats tool kind "%s".', $kind));
        }
    }

    public function name(): string
    {
        return match ($this->kind) {
            'article' => 'get_article_stats',
            'summary' => 'get_stats_summary',
            default => 'get_top_referrers',
        };
    }

    public function description(): string
    {
        return match ($this->kind) {
            'article' => 'Human and crawler views per day plus referrers for one article. Defaults to the last 30 days.',
            'summary' => 'Site or section totals by visit kind: direct, search, social, ai_referral, ai_crawler, bot. Defaults to the last 30 days.',
            default => 'Referrer hosts ranked by inbound views, with the kind of each. Use it to learn which channels and AI assistants send readers.',
        };
    }

    public function inputSchema(): array
    {
        $period = [
            'from' => ['type' => 'string', 'format' => 'date', 'description' => 'YYYY-MM-DD, inclusive.'],
            'to' => ['type' => 'string', 'format' => 'date', 'description' => 'YYYY-MM-DD, inclusive. Defaults to today.'],
        ];

        return match ($this->kind) {
            'article' => self::schema(['id' => ['type' => 'string']] + $period, ['id']),
            'summary' => self::schema(['section' => ['type' => 'string']] + $period),
            default => self::schema(['section' => ['type' => 'string']] + $period + ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 20]]),
        };
    }

    public function scope(): Scope
    {
        return Scope::StatsRead;
    }

    public function call(array $arguments, ApiKey $key): array
    {
        try {
            $period = StatsPeriod::parse(self::string($arguments, 'from'), self::string($arguments, 'to'), $this->timezone, $this->clock?->now() ?? new \DateTimeImmutable());
        } catch (ValidationException $e) {
            throw self::invalid($e);
        }
        $range = ['from' => $period->from->format('Y-m-d'), 'to' => $period->to->format('Y-m-d')];

        if ('article' === $this->kind) {
            $article = self::article($this->articles, (string) self::string($arguments, 'id', true), $key);

            return ['article_id' => $article->getId()] + $range
                + $this->stats->summary($article->getId(), null, $period->from, $period->to)
                + ['per_day' => $this->stats->viewsPerDay($article->getId(), $period->from, $period->to), 'referrers' => $this->stats->referrers($article->getId(), null, $period->from, $period->to, 50)];
        }

        $section = self::string($arguments, 'section');
        if (null === $section && 1 === \count($key->getSections())) {
            $section = $key->getSections()[0];
        } elseif (null === $section && [] !== $key->getSections()) {
            throw new ToolException(\sprintf('This key is restricted to sections %s; pass "section".', implode(', ', $key->getSections())));
        }
        self::allowSection($key, $section);
        if (null !== $section && null === $this->sections->get($section)) {
            throw new ToolException(\sprintf('Unknown section "%s".', $section));
        }

        if ('summary' === $this->kind) {
            return ['section' => $section] + $range + $this->stats->summary(null, $section, $period->from, $period->to);
        }
        $limit = self::int($arguments, 'limit', 20);
        if ($limit < 1 || $limit > 200) {
            throw new ToolException('Argument "limit" must be between 1 and 200.');
        }

        return ['section' => $section] + $range + ['items' => $this->stats->referrers(null, $section, $period->from, $period->to, $limit)];
    }
}
