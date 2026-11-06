<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Lint;

final class LintReport
{
    /** @param list<LintCheck> $checks */
    public function __construct(public readonly array $checks) {}

    public function status(): LintLevel
    {
        $worst = LintLevel::Pass;
        foreach ($this->checks as $check) {
            if ($check->level->severity() > $worst->severity()) {
                $worst = $check->level;
            }
        }

        return $worst;
    }

    /** @return array{status: string, summary: array{pass: int, warn: int, fail: int}, checks: list<array{rule: string, level: string, message: string, hint?: string}>} */
    public function toArray(): array
    {
        $summary = ['pass' => 0, 'warn' => 0, 'fail' => 0];
        foreach ($this->checks as $check) {
            ++$summary[$check->level->value];
        }

        return [
            'status' => $this->status()->value,
            'summary' => $summary,
            'checks' => array_map(static fn(LintCheck $c) => $c->toArray(), $this->checks),
        ];
    }
}
