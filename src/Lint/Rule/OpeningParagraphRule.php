<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Lint\Rule;

use MattLeads\AgentPublishingBundle\Lint\Content\ContentSummary;
use MattLeads\AgentPublishingBundle\Lint\LintCheck;
use MattLeads\AgentPublishingBundle\Lint\LintRuleInterface;
use MattLeads\AgentPublishingBundle\Lint\LintSubject;

/**
 * Answer engines quote the opening. It should be a paragraph, of a quotable length, that addresses the title.
 */
final class OpeningParagraphRule implements LintRuleInterface
{
    public function name(): string
    {
        return 'opening_paragraph';
    }

    public function check(LintSubject $subject): LintCheck
    {
        $content = $subject->content();
        $first = $content->firstParagraph();
        if (null === $first) {
            return LintCheck::fail($this->name(), 'No paragraph found in the body.', 'Open with a paragraph that answers the title in two or three sentences.');
        }
        if (!$content->startsWithParagraph) {
            return LintCheck::warn($this->name(), 'The body does not start with a paragraph.', 'Put a short answer to the title before the first heading, list or image. Answer engines quote the first paragraph.');
        }
        $words = ContentSummary::countWords($first);
        if ($words < 40) {
            return LintCheck::warn($this->name(), \sprintf('Opening paragraph has %d words.', $words), 'Make the opening 40-120 words: a direct answer plus the reason it matters.');
        }
        if ($words > 120) {
            return LintCheck::warn($this->name(), \sprintf('Opening paragraph has %d words.', $words), 'Split the opening; a 40-120 word answer is what answer engines quote.');
        }
        $terms = $subject->titleTerms();
        if ([] !== $terms) {
            $lower = mb_strtolower($first);
            $hits = array_values(array_filter($terms, static fn(string $t) => str_contains($lower, $t)));
            if ([] === $hits) {
                return LintCheck::warn($this->name(), 'Opening paragraph shares no key term with the title.', \sprintf('Use the words a reader searched for (%s) in the first paragraph.', implode(', ', \array_slice($terms, 0, 4))));
            }
        }

        return LintCheck::pass($this->name(), \sprintf('Opening paragraph has %d words and addresses the title.', $words));
    }
}
