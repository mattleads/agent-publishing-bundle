<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Tests\Unit\Analytics;

use MattLeads\AgentPublishingBundle\Analytics\VisitClassifier;
use MattLeads\AgentPublishingBundle\Analytics\VisitKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VisitClassifierTest extends TestCase
{
    private const CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    /** @return iterable<string, array{?string, ?string, VisitKind, string}> */
    public static function cases(): iterable
    {
        yield 'no referrer' => [self::CHROME, null, VisitKind::Direct, ''];
        yield 'empty referrer' => [self::CHROME, '', VisitKind::Direct, ''];
        yield 'own site' => [self::CHROME, 'https://www.example.com/blog', VisitKind::Internal, 'example.com'];
        yield 'own subdomain' => [self::CHROME, 'https://docs.example.com/', VisitKind::Internal, 'docs.example.com'];
        yield 'google' => [self::CHROME, 'https://www.google.co.uk/', VisitKind::Search, 'google.co.uk'];
        yield 'bing' => [self::CHROME, 'https://www.bing.com/search?q=x', VisitKind::Search, 'bing.com'];
        yield 'brave' => [self::CHROME, 'https://search.brave.com/', VisitKind::Search, 'search.brave.com'];
        yield 'chatgpt' => [self::CHROME, 'https://chatgpt.com/', VisitKind::AiReferral, 'chatgpt.com'];
        yield 'perplexity' => [self::CHROME, 'https://www.perplexity.ai/search/abc', VisitKind::AiReferral, 'perplexity.ai'];
        yield 'gemini' => [self::CHROME, 'https://gemini.google.com/app', VisitKind::AiReferral, 'gemini.google.com'];
        yield 'linkedin' => [self::CHROME, 'https://www.linkedin.com/feed/', VisitKind::Social, 'linkedin.com'];
        yield 'x short link' => [self::CHROME, 'https://t.co/abc', VisitKind::Social, 't.co'];
        yield 'facebook mobile' => [self::CHROME, 'https://l.facebook.com/l.php?u=x', VisitKind::Social, 'facebook.com'];
        yield 'hacker news' => [self::CHROME, 'https://news.ycombinator.com/item?id=1', VisitKind::Social, 'news.ycombinator.com'];
        yield 'other site' => [self::CHROME, 'https://symfony.com/blog/x', VisitKind::Referral, 'symfony.com'];
        yield 'gptbot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2; +https://openai.com/gptbot', 'https://google.com/', VisitKind::AiCrawler, ''];
        yield 'claudebot' => ['Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)', null, VisitKind::AiCrawler, ''];
        yield 'perplexitybot' => ['Mozilla/5.0 (compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)', null, VisitKind::AiCrawler, ''];
        yield 'google extended' => ['Mozilla/5.0 (compatible; Google-Extended)', null, VisitKind::AiCrawler, ''];
        yield 'googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', null, VisitKind::Bot, ''];
        yield 'curl' => ['curl/8.4.0', null, VisitKind::Bot, ''];
        yield 'no user agent' => [null, 'https://google.com/', VisitKind::Bot, ''];
        yield 'headless' => ['Mozilla/5.0 HeadlessChrome/120.0', null, VisitKind::Bot, ''];
        yield 'garbage referrer' => [self::CHROME, 'not a url', VisitKind::Direct, ''];
    }

    #[DataProvider('cases')]
    public function testClassify(?string $userAgent, ?string $referrer, VisitKind $kind, string $host): void
    {
        $visit = (new VisitClassifier('example.com'))->classify($userAgent, $referrer, 'ignored.test');
        self::assertSame($kind, $visit['kind']);
        self::assertSame($host, $visit['host']);
    }

    public function testRequestHostIsUsedWhenNoSiteHostIsConfigured(): void
    {
        $visit = (new VisitClassifier())->classify(self::CHROME, 'https://www.blog.test/post', 'blog.test');
        self::assertSame(VisitKind::Internal, $visit['kind']);
    }
}
