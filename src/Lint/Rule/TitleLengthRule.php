<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Lint\Rule;

use MattLeads\AgentPublishingBundle\Lint\LintCheck;
use MattLeads\AgentPublishingBundle\Lint\LintRuleInterface;
use MattLeads\AgentPublishingBundle\Lint\LintSubject;

final class TitleLengthRule implements LintRuleInterface
{
    public function name(): string
    {
        return 'title_length';
    }

    public function check(LintSubject $subject): LintCheck
    {
        $length = mb_strlen($subject->title);
        if ($length > 70) {
            return LintCheck::fail($this->name(), \sprintf('Title is %d characters; search results cut titles around 60.', $length), 'Shorten the title to 30-60 characters and move detail into the summary.');
        }
        if ($length < 30) {
            return LintCheck::warn($this->name(), \sprintf('Title is %d characters, which is short for search and social previews.', $length), 'Name the outcome and the subject: aim for 30-60 characters.');
        }

        return LintCheck::pass($this->name(), \sprintf('Title is %d characters.', $length));
    }
}
