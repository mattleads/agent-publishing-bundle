<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Seo;

use MattLeads\AgentPublishingBundle\Model\ArticleInterface;
use MattLeads\AgentPublishingBundle\Section\SectionRegistry;

/**
 * Sitemap of published articles with hreflang alternates for articles that share a translation group.
 */
final class SitemapBuilder
{
    public function __construct(
        private readonly PublishedIndex $index,
        private readonly UrlResolverInterface $urls,
        private readonly SectionRegistry $sections,
    ) {}

    public function build(): string
    {
        $articles = $this->index->all();
        $groups = PublishedIndex::groups($articles);
        $default = $this->sections->defaultLocale();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
        foreach ($articles as $article) {
            $xml .= "  <url>\n"
                . '    <loc>' . self::escape($this->urls->urlFor($article)) . "</loc>\n"
                . '    <lastmod>' . $article->getUpdatedAt()->format(\DateTimeInterface::ATOM) . "</lastmod>\n";
            $group = $article->getTranslationGroup();
            if (null !== $group && \count($groups[$group]) > 1) {
                foreach ($groups[$group] as $translation) {
                    $xml .= $this->alternate($translation->getLocale(), $translation);
                    if ($translation->getLocale() === $default) {
                        $xml .= $this->alternate('x-default', $translation);
                    }
                }
            }
            $xml .= "  </url>\n";
        }

        return $xml . '</urlset>' . "\n";
    }

    private function alternate(string $hreflang, ArticleInterface $article): string
    {
        return '    <xhtml:link rel="alternate" hreflang="' . self::escape($hreflang) . '" href="' . self::escape($this->urls->urlFor($article)) . '"/>' . "\n";
    }

    private static function escape(string $s): string
    {
        return htmlspecialchars($s, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
    }
}
