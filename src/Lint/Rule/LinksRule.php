<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Lint\Rule;

use MattLeads\AgentPublishingBundle\Lint\LintCheck;
use MattLeads\AgentPublishingBundle\Lint\LintRuleInterface;
use MattLeads\AgentPublishingBundle\Lint\LintSubject;

/** Internal links keep readers and crawlers on the site; vague link text helps neither. */
final class LinksRule implements LintRuleInterface
{
    private const VAGUE = ['here', 'click here', 'link', 'this', 'read more', 'more', 'this link', 'this page'];

    public function __construct(private readonly ?string $siteHost = null) {}

    public function name(): string
    {
        return 'links';
    }

    public function check(LintSubject $subject): LintCheck
    {
        $content = $subject->content();
        $internal = 0;
        foreach ($content->links as $link) {
            $href = $link['href'];
            $text = mb_strtolower(trim($link['text']));
            if (\in_array($text, self::VAGUE, true)) {
                return LintCheck::warn($this->name(), \sprintf('Link text "%s" says nothing about the target.', $link['text']), 'Make link text describe the destination, e.g. "the Messenger retry docs".');
            }
            if (str_starts_with($href, '/') || str_starts_with($href, '#') || (null !== $this->siteHost && str_contains($href, '//' . $this->siteHost))) {
                ++$internal;
            }
        }
        $min = $subject->section->lint->minInternalLinks;
        if ($content->words >= 300 && $internal < $min) {
            return LintCheck::warn($this->name(), \sprintf('%d internal link(s); this section expects at least %d.', $internal, $min), 'Link to related articles on this site so readers and crawlers find them.');
        }

        return LintCheck::pass($this->name(), \sprintf('%d link(s), %d internal.', \count($content->links), $internal));
    }
}
