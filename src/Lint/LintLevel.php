<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Lint;

enum LintLevel: string
{
    case Pass = 'pass';
    case Warn = 'warn';
    case Fail = 'fail';

    public function severity(): int
    {
        return match ($this) {
            self::Pass => 0,
            self::Warn => 1,
            self::Fail => 2,
        };
    }
}
