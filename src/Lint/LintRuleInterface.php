<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Lint;

/**
 * One check. Register your own as a service tagged "mattleads_agent_publishing.lint_rule" to extend the report.
 */
interface LintRuleInterface
{
    public function name(): string;

    /** Return null to skip the rule for this subject (for example a language it cannot judge). */
    public function check(LintSubject $subject): ?LintCheck;
}
