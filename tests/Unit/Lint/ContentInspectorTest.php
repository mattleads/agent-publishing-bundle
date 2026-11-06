<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Tests\Unit\Lint;

use MattLeads\AgentPublishingBundle\Lint\Content\ContentInspector;
use PHPUnit\Framework\TestCase;

final class ContentInspectorTest extends TestCase
{
    public function testMarkdown(): void
    {
        $md = <<<'MD'
            Symfony Messenger retries a failed message with a growing delay. This opening paragraph explains the behaviour.

            ## How retries work

            The `RedeliveryStamp` counts attempts. See [the retry docs](/docs/retries) and [Symfony](https://symfony.com/doc).

            ```php
            # not a heading
            $x = 1;
            ```

            ### Configuration

            - one
            - two

            ![A worker loop](/img/worker.png) ![](/img/missing-alt.png)

            Second paragraph with **bold** and `code`.
            MD;

        $c = (new ContentInspector())->inspect($md, 'markdown');

        self::assertSame([['level' => 2, 'text' => 'How retries work'], ['level' => 3, 'text' => 'Configuration']], $c->headings);
        self::assertTrue($c->startsWithParagraph);
        self::assertStringStartsWith('Symfony Messenger retries a failed message', (string) $c->firstParagraph());
        self::assertSame([['src' => '/img/worker.png', 'alt' => 'A worker loop'], ['src' => '/img/missing-alt.png', 'alt' => '']], $c->images);
        self::assertSame([['href' => '/docs/retries', 'text' => 'the retry docs'], ['href' => 'https://symfony.com/doc', 'text' => 'Symfony']], $c->links);
        self::assertStringNotContainsString('$x = 1', $c->text, 'code blocks are excluded');
        self::assertStringNotContainsString('not a heading', $c->text);
        self::assertStringContainsString('Second paragraph with bold and code.', $c->text);
        self::assertGreaterThan(30, $c->words);
    }

    public function testMarkdownStartingWithHeading(): void
    {
        $c = (new ContentInspector())->inspect("# Title\n\nText here.", 'markdown');
        self::assertFalse($c->startsWithParagraph);
        self::assertSame([['level' => 1, 'text' => 'Title']], $c->headings);
        self::assertSame(['Text here.'], $c->paragraphs);
    }

    public function testHtml(): void
    {
        $html = '<p>Opening paragraph about <a href="/x">internal links</a>.</p><h2>Section</h2><h4>Skipped</h4><p>More.</p><img src="/a.png" alt="An image"><img src="/b.png"><pre><code>ignored()</code></pre>';
        $c = (new ContentInspector())->inspect($html, 'html');

        self::assertTrue($c->startsWithParagraph);
        self::assertSame([['level' => 2, 'text' => 'Section'], ['level' => 4, 'text' => 'Skipped']], $c->headings);
        self::assertSame(['Opening paragraph about internal links.', 'More.'], $c->paragraphs);
        self::assertSame([['src' => '/a.png', 'alt' => 'An image'], ['src' => '/b.png', 'alt' => '']], $c->images);
        self::assertSame([['href' => '/x', 'text' => 'internal links']], $c->links);
        self::assertStringNotContainsString('ignored()', $c->text);
    }
}
