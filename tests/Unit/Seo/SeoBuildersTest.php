<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Tests\Unit\Seo;

use MattLeads\AgentPublishingBundle\Model\ArticleInput;
use MattLeads\AgentPublishingBundle\Model\ArticleInterface;
use MattLeads\AgentPublishingBundle\Model\ArticleState;
use MattLeads\AgentPublishingBundle\Section\SectionRegistry;
use MattLeads\AgentPublishingBundle\Seo\JsonLdBuilder;
use MattLeads\AgentPublishingBundle\Seo\LlmsTxtBuilder;
use MattLeads\AgentPublishingBundle\Seo\PatternUrlResolver;
use MattLeads\AgentPublishingBundle\Seo\PublishedIndex;
use MattLeads\AgentPublishingBundle\Seo\SitemapBuilder;
use MattLeads\AgentPublishingBundle\Storage\InMemoryArticleStorage;
use MattLeads\AgentPublishingBundle\Twig\JsonLdExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class SeoBuildersTest extends TestCase
{
    private InMemoryArticleStorage $storage;
    private SectionRegistry $sections;
    private PatternUrlResolver $urls;

    protected function setUp(): void
    {
        $this->storage = new InMemoryArticleStorage();
        $this->sections = new SectionRegistry(['blog' => ['path' => '/blog', 'locales' => []], 'docs' => ['path' => '/docs/', 'locales' => ['en']]], ['en', 'de'], 'en');
        $this->urls = new PatternUrlResolver($this->sections, '{base}/{locale}{section_path}/{slug}', 'https://example.com/');
    }

    public function testJsonLd(): void
    {
        $article = $this->published('blog', 'en', 'Hello <World>', 'hello', 'A summary.', ['php', 'symfony'], 'g1');
        $builder = new JsonLdBuilder($this->urls, 'TechArticle', ['name' => 'Example Ltd', 'url' => 'https://example.com', 'logo' => 'https://example.com/logo.png'], ['name' => 'Jane', 'url' => null]);

        $data = $builder->build($article, ['image' => 'https://example.com/c.png']);
        self::assertSame('https://schema.org', $data['@context']);
        self::assertSame('TechArticle', $data['@type']);
        self::assertSame('Hello <World>', $data['headline']);
        self::assertSame('https://example.com/en/blog/hello', $data['url']);
        self::assertSame(['@type' => 'WebPage', '@id' => 'https://example.com/en/blog/hello'], $data['mainEntityOfPage']);
        self::assertSame('A summary.', $data['description']);
        self::assertSame('php, symfony', $data['keywords']);
        self::assertSame('2026-11-06T10:00:00+00:00', $data['datePublished']);
        self::assertSame(['@type' => 'Person', 'name' => 'Jane'], $data['author']);
        self::assertSame(['@type' => 'Organization', 'name' => 'Example Ltd', 'url' => 'https://example.com', 'logo' => ['@type' => 'ImageObject', 'url' => 'https://example.com/logo.png']], $data['publisher']);
        self::assertSame('https://example.com/c.png', $data['image']);

        $script = $builder->script($article);
        self::assertStringStartsWith('<script type="application/ld+json">{', $script);
        self::assertStringContainsString("\"headline\":\"Hello \u003CWorld\u003E\"", $script, 'angle brackets are escaped so the JSON cannot close the script tag');
        self::assertStringNotContainsString('author', (new JsonLdBuilder($this->urls))->script($article), 'no author or publisher when none is configured');

        $twig = new Environment(new ArrayLoader(['t' => '{{ agent_publishing_jsonld(a) }}|{{ agent_publishing_url(a) }}']));
        $twig->addExtension(new JsonLdExtension($builder, $this->urls));
        $rendered = $twig->render('t', ['a' => $article]);
        self::assertStringStartsWith('<script type="application/ld+json">', $rendered, 'the function output is not escaped by Twig');
        self::assertStringEndsWith('|https://example.com/en/blog/hello', $rendered);
    }

    public function testSitemapWithTranslations(): void
    {
        $en = $this->published('blog', 'en', 'Hello', 'hello', null, [], 'g1');
        $de = $this->published('blog', 'de', 'Hallo', 'hallo', null, [], 'g1');
        $alone = $this->published('docs', 'en', 'Alone', 'alone', null, [], null);
        $this->storage->create(ArticleInput::fromArray(['section' => 'blog', 'locale' => 'en', 'title' => 'Draft', 'body' => 'x']), 'draft', null, new \DateTimeImmutable());

        $xml = (new SitemapBuilder(new PublishedIndex($this->storage), $this->urls, $this->sections))->build();

        self::assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        self::assertStringContainsString('<loc>https://example.com/en/blog/hello</loc>', $xml);
        self::assertStringContainsString('<loc>https://example.com/de/blog/hallo</loc>', $xml);
        self::assertStringContainsString('<loc>https://example.com/en/docs/alone</loc>', $xml);
        self::assertStringNotContainsString('draft', $xml);
        self::assertSame(2, substr_count($xml, '<xhtml:link rel="alternate" hreflang="de" href="https://example.com/de/blog/hallo"/>'), 'both translations list the de alternate');
        self::assertSame(2, substr_count($xml, 'hreflang="x-default" href="https://example.com/en/blog/hello"'));
        self::assertStringNotContainsString('hreflang="en" href="https://example.com/en/docs/alone"', $xml, 'no alternates without a group');
        self::assertSame(3, substr_count($xml, '<url>'));

        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($xml), 'well-formed XML');
        self::assertSame(3, $dom->getElementsByTagNameNS('http://www.sitemaps.org/schemas/sitemap/0.9', 'url')->length);
        unset($en, $de, $alone);
    }

    public function testLlmsTxt(): void
    {
        $this->published('blog', 'en', 'Hello [bracket]', 'hello', "Line one\nline two", [], null);
        $this->published('blog', 'de', 'Hallo', 'hallo', null, [], null);
        $txt = (new LlmsTxtBuilder(new PublishedIndex($this->storage), $this->urls, $this->sections, 'My Site', "About\nthe site", 50))->build();

        self::assertSame("# My Site\n\n> About the site\n\n## Blog\n\n- [Hallo](https://example.com/de/blog/hallo)\n- [Hello (bracket)](https://example.com/en/blog/hello): Line one line two\n", $txt, 'newest first');
        self::assertStringNotContainsString('## Docs', $txt, 'empty sections are omitted');

        $limited = (new LlmsTxtBuilder(new PublishedIndex($this->storage), $this->urls, $this->sections, null, null, 1))->build();
        self::assertStringStartsWith("# Articles\n\n## Blog\n\n", $limited);
        self::assertSame(1, substr_count($limited, "\n- "));
    }

    public function testUrlResolverFallsBackToTheRequestHost(): void
    {
        $resolver = new PatternUrlResolver($this->sections);
        self::assertSame('/blog/x', $resolver->urlFor($this->published('blog', 'en', 'X', 'x', null, [], null)), 'no request and no base_url yields a path');
    }

    /** @param list<string> $tags */
    private function published(string $section, string $locale, string $title, string $slug, ?string $summary, array $tags, ?string $group): ArticleInterface
    {
        $now = new \DateTimeImmutable('2026-11-06 10:00:00', new \DateTimeZone('UTC'));
        $article = $this->storage->create(ArticleInput::fromArray(['section' => $section, 'locale' => $locale, 'title' => $title, 'body' => 'Body', 'summary' => $summary, 'tags' => $tags, 'translation_group' => $group]), $slug, null, $now);

        return $this->storage->transition($article, ArticleState::Published, $now);
    }
}
