<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Analytics;

use MattLeads\AgentPublishingBundle\Contract\StatsStorageInterface;

final class ArticleViewedHandler
{
    public function __construct(private readonly StatsStorageInterface $stats, private readonly \DateTimeZone $timezone) {}

    public function __invoke(ArticleViewed $message): void
    {
        $day = $message->viewedAt->setTimezone($this->timezone)->setTime(0, 0);
        $this->stats->recordView($message->articleId, $message->section, $day, $message->referrerHost, $message->kind);
    }
}
