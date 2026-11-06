<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Lint\Rule;

use MattLeads\AgentPublishingBundle\Contract\ArticleStorageInterface;
use MattLeads\AgentPublishingBundle\Lint\LintCheck;
use MattLeads\AgentPublishingBundle\Lint\LintRuleInterface;
use MattLeads\AgentPublishingBundle\Lint\LintSubject;

final class SlugRule implements LintRuleInterface
{
    public function __construct(private readonly ArticleStorageInterface $storage) {}

    public function name(): string
    {
        return 'slug';
    }

    public function check(LintSubject $subject): LintCheck
    {
        $slug = $subject->slug;
        if (null === $slug || '' === $slug) {
            return LintCheck::pass($this->name(), 'Slug will be derived from the title.');
        }
        if ($this->storage->slugExists($subject->section->name, $subject->locale, $slug, $subject->articleId)) {
            return LintCheck::fail($this->name(), \sprintf('Slug "%s" is already used in %s/%s.', $slug, $subject->section->name, $subject->locale), 'Choose a distinct slug; two articles cannot share a URL.');
        }
        if (\strlen($slug) > 75) {
            return LintCheck::warn($this->name(), \sprintf('Slug is %d characters.', \strlen($slug)), 'Keep slugs under 75 characters; drop stop words.');
        }
        $terms = $subject->titleTerms();
        if ([] !== $terms && [] === array_filter($terms, static fn(string $t) => str_contains($slug, $t))) {
            return LintCheck::warn($this->name(), 'Slug shares no key term with the title.', 'Put the main subject of the title in the slug.');
        }

        return LintCheck::pass($this->name(), \sprintf('Slug "%s" is unique and descriptive.', $slug));
    }
}
