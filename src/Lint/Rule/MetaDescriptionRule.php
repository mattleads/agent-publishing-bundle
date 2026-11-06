<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Lint\Rule;

use MattLeads\AgentPublishingBundle\Lint\LintCheck;
use MattLeads\AgentPublishingBundle\Lint\LintRuleInterface;
use MattLeads\AgentPublishingBundle\Lint\LintSubject;

/** A meta description set apart from the summary must still fit a search result. */
final class MetaDescriptionRule implements LintRuleInterface
{
    public function name(): string
    {
        return 'meta_description';
    }

    public function check(LintSubject $subject): LintCheck
    {
        $description = trim((string) $subject->metaDescription);
        if ('' === $description) {
            return '' === trim((string) $subject->summary)
                ? LintCheck::warn($this->name(), 'No meta description and no summary.', 'Set meta_description or summary, 70-160 characters.')
                : LintCheck::pass($this->name(), 'The summary is used as the meta description.');
        }
        $length = mb_strlen($description);
        if ($length > 160) {
            return LintCheck::warn($this->name(), \sprintf('Meta description is %d characters; search engines truncate around 160.', $length), 'Keep it to 160 characters.');
        }
        if ($length < 70) {
            return LintCheck::warn($this->name(), \sprintf('Meta description is %d characters, which is short.', $length), 'Aim for 70-160 characters.');
        }

        return LintCheck::pass($this->name(), \sprintf('Meta description is %d characters.', $length));
    }
}
