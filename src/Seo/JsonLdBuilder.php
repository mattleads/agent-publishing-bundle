<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Seo;

use MattLeads\AgentPublishingBundle\Model\ArticleInterface;

/**
 * schema.org Article markup for a published article. Drop the result into the page head as
 * <script type="application/ld+json"> (the Twig function does that for you).
 */
final class JsonLdBuilder
{
    public const TYPES = ['Article', 'BlogPosting', 'NewsArticle', 'TechArticle'];

    /**
     * @param array{name: ?string, url: ?string, logo: ?string} $publisher
     * @param array{name: ?string, url: ?string}                $author
     */
    public function __construct(
        private readonly UrlResolverInterface $urls,
        private readonly string $type = 'Article',
        private readonly array $publisher = ['name' => null, 'url' => null, 'logo' => null],
        private readonly array $author = ['name' => null, 'url' => null],
    ) {}

    /**
     * @param array<string, mixed> $overrides merged last, so any key can be replaced or added (e.g. "image", "author")
     *
     * @return array<string, mixed>
     */
    public function build(ArticleInterface $article, array $overrides = []): array
    {
        $url = $this->urls->urlFor($article);
        $data = [
            '@context' => 'https://schema.org',
            '@type' => $this->type,
            'headline' => mb_substr($article->getTitle(), 0, 110),
            'url' => $url,
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
            'inLanguage' => $article->getLocale(),
            'articleSection' => $article->getSection(),
            'dateModified' => $article->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
        if (null !== $summary = $article->getSummary()) {
            $data['description'] = $summary;
        }
        if (null !== $published = $article->getPublishedAt()) {
            $data['datePublished'] = $published->format(\DateTimeInterface::ATOM);
        }
        if ([] !== $article->getTags()) {
            $data['keywords'] = implode(', ', $article->getTags());
        }
        if (null !== $this->author['name']) {
            $data['author'] = array_filter(['@type' => 'Person', 'name' => $this->author['name'], 'url' => $this->author['url']]);
        }
        if (null !== $this->publisher['name']) {
            $publisher = array_filter(['@type' => 'Organization', 'name' => $this->publisher['name'], 'url' => $this->publisher['url']]);
            if (null !== $this->publisher['logo']) {
                $publisher['logo'] = ['@type' => 'ImageObject', 'url' => $this->publisher['logo']];
            }
            $data['publisher'] = $publisher;
        }

        return array_replace($data, $overrides);
    }

    /** @param array<string, mixed> $overrides */
    public function script(ArticleInterface $article, array $overrides = []): string
    {
        $json = json_encode($this->build($article, $overrides), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_HEX_TAG | \JSON_THROW_ON_ERROR);

        return '<script type="application/ld+json">' . $json . '</script>';
    }
}
