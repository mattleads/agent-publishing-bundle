<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Analytics;

enum VisitKind: string
{
    case Direct = 'direct';
    case Internal = 'internal';
    case Referral = 'referral';
    case Search = 'search';
    case Social = 'social';
    case AiReferral = 'ai_referral';
    case AiCrawler = 'ai_crawler';
    case Bot = 'bot';

    /** Human kinds count as views; crawler kinds are counted separately. */
    public function isHuman(): bool
    {
        return self::AiCrawler !== $this && self::Bot !== $this;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn(self $k) => $k->value, self::cases());
    }
}
