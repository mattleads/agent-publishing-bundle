<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Lint\Rule;

use MattLeads\AgentPublishingBundle\Lint\LintCheck;
use MattLeads\AgentPublishingBundle\Lint\LintRuleInterface;
use MattLeads\AgentPublishingBundle\Lint\LintSubject;

/** The site renders the title as H1, so the body should start at H2 and not skip levels. */
final class HeadingsRule implements LintRuleInterface
{
    public function name(): string
    {
        return 'headings';
    }

    public function check(LintSubject $subject): LintCheck
    {
        $content = $subject->content();
        if ([] !== $content->headingsOfLevel(1)) {
            return LintCheck::warn($this->name(), 'Body contains an H1. The title is already rendered as the page H1.', 'Use H2 for sections and H3 for sub-sections.');
        }
        $previous = 1;
        foreach ($content->headings as $heading) {
            if ($heading['level'] > $previous + 1) {
                return LintCheck::warn($this->name(), \sprintf('Heading "%s" is H%d after H%d; levels skip.', $heading['text'], $heading['level'], $previous), 'Do not skip heading levels; screen readers and outline parsers rely on the order.');
            }
            $previous = $heading['level'];
        }
        $h2 = \count($content->headingsOfLevel(2));
        if ($content->words > 500 && $h2 < 2) {
            return LintCheck::warn($this->name(), \sprintf('%d words with %d H2 heading(s).', $content->words, $h2), 'Break long articles into sections with H2 headings so readers and answer engines can jump to the relevant part.');
        }

        return LintCheck::pass($this->name(), \sprintf('%d heading(s), %d of them H2, no skipped levels.', \count($content->headings), $h2));
    }
}
