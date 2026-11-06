<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Lint\Rule;

use MattLeads\AgentPublishingBundle\Lint\LintCheck;
use MattLeads\AgentPublishingBundle\Lint\LintRuleInterface;
use MattLeads\AgentPublishingBundle\Lint\LintSubject;

final class WordCountRule implements LintRuleInterface
{
    public function name(): string
    {
        return 'word_count';
    }

    public function check(LintSubject $subject): LintCheck
    {
        $words = $subject->content()->words;
        $min = $subject->section->lint->minWords;
        if ($words < $min) {
            return LintCheck::warn($this->name(), \sprintf('%d words; this section expects at least %d.', $words, $min), 'Thin pages rank poorly. Add the detail a reader needs to act, or merge with a related article.');
        }

        return LintCheck::pass($this->name(), \sprintf('%d words.', $words));
    }
}
