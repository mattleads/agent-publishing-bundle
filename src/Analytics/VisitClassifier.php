<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Analytics;

/**
 * Buckets a page view by user agent and referrer. The result is a kind plus a normalized referrer host, and nothing
 * else about the visitor is kept.
 */
final class VisitClassifier
{
    /** User agent fragments of crawlers run by AI companies, matched case-insensitively. */
    public const AI_CRAWLERS = [
        'GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'ClaudeBot', 'Claude-Web', 'Claude-User', 'Claude-SearchBot', 'anthropic-ai',
        'PerplexityBot', 'Perplexity-User', 'Google-Extended', 'GoogleOther', 'CCBot', 'Bytespider', 'Amazonbot', 'Applebot-Extended',
        'Meta-ExternalAgent', 'Meta-ExternalFetcher', 'cohere-ai', 'YouBot', 'DuckAssistBot', 'Diffbot', 'MistralAI-User', 'AI2Bot', 'omgili',
    ];

    public const AI_REFERRERS = [
        'chatgpt.com', 'chat.openai.com', 'openai.com', 'perplexity.ai', 'claude.ai', 'gemini.google.com', 'bard.google.com',
        'copilot.microsoft.com', 'you.com', 'phind.com', 'mistral.ai', 'chat.mistral.ai', 'kagi.com', 'poe.com', 'character.ai', 'meta.ai',
    ];

    public const SEARCH_ENGINES = [
        'google.', 'bing.com', 'duckduckgo.com', 'yahoo.', 'yandex.', 'baidu.com', 'ecosia.org', 'search.brave.com', 'startpage.com',
        'qwant.com', 'seznam.cz', 'naver.com', 'ask.com', 'aol.com',
    ];

    public const SOCIAL = [
        'facebook.com', 'fb.com', 'instagram.com', 'threads.net', 'twitter.com', 'x.com', 't.co', 'linkedin.com', 'lnkd.in', 'reddit.com',
        'news.ycombinator.com', 'lobste.rs', 't.me', 'telegram.me', 'bsky.app', 'mastodon.social', 'pinterest.', 'youtube.com', 'youtu.be',
        'tiktok.com', 'vk.com', 'whatsapp.com', 'slack.com', 'discord.com', 'dev.to', 'medium.com', 'substack.com',
    ];

    private const GENERIC_BOT = '/bot|crawl|spider|slurp|fetch|scan|curl\/|wget\/|python-requests|python-urllib|go-http-client|java\/|okhttp|libwww|httpclient|headlesschrome|phantomjs|lighthouse|pingdom|uptime|monitor|facebookexternalhit|preview/i';

    public function __construct(private readonly ?string $siteHost = null) {}

    /** @return array{kind: VisitKind, host: string} host is "" when there is no usable referrer */
    public function classify(?string $userAgent, ?string $referrer, ?string $requestHost = null): array
    {
        $ua = (string) $userAgent;
        foreach (self::AI_CRAWLERS as $fragment) {
            if (false !== stripos($ua, $fragment)) {
                return ['kind' => VisitKind::AiCrawler, 'host' => ''];
            }
        }
        if ('' === trim($ua) || preg_match(self::GENERIC_BOT, $ua)) {
            return ['kind' => VisitKind::Bot, 'host' => ''];
        }

        $host = self::normalizeHost($referrer);
        if ('' === $host) {
            return ['kind' => VisitKind::Direct, 'host' => ''];
        }
        $own = self::normalizeHost('http://' . ($this->siteHost ?? $requestHost ?? ''));
        if ('' !== $own && ($host === $own || str_ends_with($host, '.' . $own))) {
            return ['kind' => VisitKind::Internal, 'host' => $host];
        }
        if (self::matches($host, self::AI_REFERRERS)) {
            return ['kind' => VisitKind::AiReferral, 'host' => $host];
        }
        if (self::matches($host, self::SEARCH_ENGINES)) {
            return ['kind' => VisitKind::Search, 'host' => $host];
        }
        if (self::matches($host, self::SOCIAL)) {
            return ['kind' => VisitKind::Social, 'host' => $host];
        }

        return ['kind' => VisitKind::Referral, 'host' => $host];
    }

    /** Lower-cased host without a "www.", "m." or "l." prefix, or "" when the referrer is unusable. */
    public static function normalizeHost(?string $referrer): string
    {
        if (null === $referrer || '' === trim($referrer)) {
            return '';
        }
        $host = parse_url(trim($referrer), \PHP_URL_HOST);
        if (!\is_string($host) || '' === $host) {
            return '';
        }
        $host = strtolower($host);
        $host = (string) preg_replace('/^(www|m|l|lm|mobile|amp)\./', '', $host);

        return mb_substr($host, 0, 255);
    }

    /** @param list<string> $patterns a pattern ending in "." matches any TLD, otherwise the host or a subdomain */
    private static function matches(string $host, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (str_ends_with($pattern, '.')) {
                if (str_starts_with($host, $pattern) || str_contains($host, '.' . $pattern)) {
                    return true;
                }
            } elseif ($host === $pattern || str_ends_with($host, '.' . $pattern)) {
                return true;
            }
        }

        return false;
    }
}
