<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Lint\Rule;

use MattLeads\AgentPublishingBundle\Lint\LintCheck;
use MattLeads\AgentPublishingBundle\Lint\LintRuleInterface;
use MattLeads\AgentPublishingBundle\Lint\LintSubject;

final class TagsRule implements LintRuleInterface
{
    public function name(): string
    {
        return 'tags';
    }

    public function check(LintSubject $subject): LintCheck
    {
        $count = \count($subject->tags);
        if (0 === $count) {
            return LintCheck::warn($this->name(), 'No tags.', 'Add 2-5 tags; they feed related-article lists and topic pages.');
        }
        if ($count > 8) {
            return LintCheck::warn($this->name(), \sprintf('%d tags.', $count), 'Keep to the 2-5 tags that describe the article best.');
        }

        return LintCheck::pass($this->name(), \sprintf('%d tag(s).', $count));
    }
}
