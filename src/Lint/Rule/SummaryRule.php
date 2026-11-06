<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Lint\Rule;

use MattLeads\AgentPublishingBundle\Lint\LintCheck;
use MattLeads\AgentPublishingBundle\Lint\LintRuleInterface;
use MattLeads\AgentPublishingBundle\Lint\LintSubject;

/** The summary doubles as the meta description. */
final class SummaryRule implements LintRuleInterface
{
    public function name(): string
    {
        return 'summary';
    }

    public function check(LintSubject $subject): LintCheck
    {
        $summary = trim((string) $subject->summary);
        if ('' === $summary) {
            return LintCheck::fail($this->name(), 'No summary. It is used as the meta description and in previews.', 'Add a 70-160 character summary that states what the reader gets.');
        }
        if (mb_strtolower($summary) === mb_strtolower(trim($subject->title))) {
            return LintCheck::warn($this->name(), 'Summary repeats the title.', 'Say what the article delivers rather than restating the title.');
        }
        $length = mb_strlen($summary);
        if ($length > 160) {
            return LintCheck::warn($this->name(), \sprintf('Summary is %d characters; search engines truncate around 160.', $length), 'Keep the first 160 characters self-contained.');
        }
        if ($length < 70) {
            return LintCheck::warn($this->name(), \sprintf('Summary is %d characters, which is short for a meta description.', $length), 'Aim for 70-160 characters.');
        }

        return LintCheck::pass($this->name(), \sprintf('Summary is %d characters.', $length));
    }
}
