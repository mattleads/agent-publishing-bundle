<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Seo;

use MattLeads\AgentPublishingBundle\Section\SectionRegistry;

/**
 * /llms.txt as proposed at llmstxt.org: a Markdown index that tells language models what the site contains.
 */
final class LlmsTxtBuilder
{
    public function __construct(
        private readonly PublishedIndex $index,
        private readonly UrlResolverInterface $urls,
        private readonly SectionRegistry $sections,
        private readonly ?string $siteName = null,
        private readonly ?string $siteDescription = null,
        private readonly int $perSection = 50,
    ) {}

    public function build(): string
    {
        $out = '# ' . ($this->siteName ?? 'Articles') . "\n\n";
        if (null !== $this->siteDescription) {
            $out .= '> ' . str_replace("\n", ' ', $this->siteDescription) . "\n\n";
        }
        foreach ($this->sections->all() as $section) {
            $articles = \array_slice($this->index->all($section->name), 0, $this->perSection);
            if ([] === $articles) {
                continue;
            }
            $out .= '## ' . ucfirst($section->name) . "\n\n";
            foreach ($articles as $article) {
                $line = '- [' . str_replace(['[', ']'], ['(', ')'], $article->getTitle()) . '](' . $this->urls->urlFor($article) . ')';
                if (null !== $summary = $article->getSummary()) {
                    $line .= ': ' . str_replace("\n", ' ', $summary);
                }
                $out .= $line . "\n";
            }
            $out .= "\n";
        }

        return rtrim($out) . "\n";
    }
}
