<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Lint\Rule;

use MattLeads\AgentPublishingBundle\Lint\LintCheck;
use MattLeads\AgentPublishingBundle\Lint\LintRuleInterface;
use MattLeads\AgentPublishingBundle\Lint\LintSubject;

/** Sections that opt in require a FAQ block, which answer engines pick up as question/answer pairs. */
final class FaqRule implements LintRuleInterface
{
    public function name(): string
    {
        return 'faq';
    }

    public function check(LintSubject $subject): ?LintCheck
    {
        if (!$subject->section->lint->requireFaq) {
            return null;
        }
        foreach ($subject->content()->headings as $heading) {
            if (preg_match('/\b(faq|frequently asked|questions?)\b/iu', $heading['text'])) {
                return LintCheck::pass($this->name(), \sprintf('FAQ section found: "%s".', $heading['text']));
            }
        }

        return LintCheck::fail($this->name(), 'This section requires a FAQ block and none was found.', 'Add an H2 "FAQ" with 3-5 question headings, each answered in one short paragraph.');
    }
}
