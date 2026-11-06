<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Lint\Rule;

use MattLeads\AgentPublishingBundle\Lint\Content\ContentSummary;
use MattLeads\AgentPublishingBundle\Lint\LintCheck;
use MattLeads\AgentPublishingBundle\Lint\LintRuleInterface;
use MattLeads\AgentPublishingBundle\Lint\LintSubject;

/** Flesch reading ease for English bodies; skipped for other languages. */
final class ReadabilityRule implements LintRuleInterface
{
    public function name(): string
    {
        return 'readability';
    }

    public function check(LintSubject $subject): ?LintCheck
    {
        if (!str_starts_with(strtolower($subject->locale), 'en')) {
            return null;
        }
        $text = $subject->content()->text;
        $words = ContentSummary::countWords($text);
        if ($words < 100) {
            return null;
        }
        $sentences = max(1, preg_match_all('/[.!?]+(\s|$)/u', $text));
        preg_match_all('/\p{L}+/u', mb_strtolower($text), $m);
        $syllables = array_sum(array_map(self::syllables(...), $m[0]));
        $score = 206.835 - 1.015 * ($words / $sentences) - 84.6 * ($syllables / max(1, \count($m[0])));
        $score = (int) round(max(0, min(100, $score)));

        if ($score < 30) {
            return LintCheck::warn($this->name(), \sprintf('Flesch reading ease %d (very hard), about %.0f words per sentence.', $score, $words / $sentences), 'Shorten sentences and prefer common words; aim for 40 or higher for a technical audience.');
        }

        return LintCheck::pass($this->name(), \sprintf('Flesch reading ease %d, about %.0f words per sentence.', $score, $words / $sentences));
    }

    private static function syllables(string $word): int
    {
        $word = (string) preg_replace('/e$/', '', $word);
        $count = preg_match_all('/[aeiouy]+/', $word);

        return max(1, $count);
    }
}
