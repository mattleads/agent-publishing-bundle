<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Lint;

final class Linter
{
    /** @var list<LintRuleInterface> */
    private readonly array $rules;

    /** @param iterable<LintRuleInterface> $rules */
    public function __construct(iterable $rules)
    {
        $this->rules = \is_array($rules) ? array_values($rules) : iterator_to_array($rules, false);
    }

    public function lint(LintSubject $subject): LintReport
    {
        $checks = [];
        foreach ($this->rules as $rule) {
            $check = $rule->check($subject);
            if (null !== $check) {
                $checks[] = $check;
            }
        }

        return new LintReport($checks);
    }

    /** @return list<string> */
    public function ruleNames(): array
    {
        return array_map(static fn(LintRuleInterface $r) => $r->name(), $this->rules);
    }
}
