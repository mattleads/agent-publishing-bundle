<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Lint\Content;

/**
 * Extracts headings, paragraphs, images, links and plain text from Markdown or HTML. It is a heuristic reader for
 * lint purposes, not a renderer.
 */
final class ContentInspector
{
    public function inspect(string $body, string $format): ContentSummary
    {
        return 'html' === $format ? $this->html($body) : $this->markdown($body);
    }

    private function markdown(string $body): ContentSummary
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        // Drop fenced code blocks before anything else.
        $body = (string) preg_replace('/^(`{3,}|~{3,})[^\n]*\n.*?^\1[ \t]*$/ms', '', $body);

        $headings = $paragraphs = $images = $links = [];
        $startsWithParagraph = null;
        $blocks = preg_split('/\n{2,}/', $body) ?: [];
        foreach ($blocks as $block) {
            $block = trim($block);
            if ('' === $block) {
                continue;
            }
            if (preg_match('/^(#{1,6})[ \t]+(.+?)[ \t#]*$/m', $block, $m) && str_starts_with($block, '#')) {
                $startsWithParagraph ??= false;
                $lines = explode("\n", $block);
                foreach ($lines as $line) {
                    if (preg_match('/^(#{1,6})[ \t]+(.+?)[ \t#]*$/', trim($line), $hm)) {
                        $headings[] = ['level' => \strlen($hm[1]), 'text' => self::inline($hm[2])];
                    } elseif ('' !== trim($line)) {
                        $paragraphs[] = self::inline($line);
                    }
                }
                continue;
            }
            // Setext headings.
            if (preg_match('/^(.+)\n(=+|-+)[ \t]*$/', $block, $m)) {
                $startsWithParagraph ??= false;
                $headings[] = ['level' => str_starts_with($m[2], '=') ? 1 : 2, 'text' => self::inline($m[1])];
                continue;
            }
            $isStructural = preg_match('/^([-*+]|\d+[.)])[ \t]/', $block) || str_starts_with($block, '>') || str_starts_with($block, '|') || str_starts_with($block, '<');
            $startsWithParagraph ??= !$isStructural && !str_starts_with($block, '![');
            $paragraphs[] = self::inline($block);
        }

        preg_match_all('/!\[([^\]]*)\]\(([^)\s]+)[^)]*\)/', $body, $m, \PREG_SET_ORDER);
        foreach ($m as $img) {
            $images[] = ['src' => $img[2], 'alt' => trim($img[1])];
        }
        preg_match_all('/(?<!!)\[([^\]]+)\]\(([^)\s]+)[^)]*\)/', $body, $m, \PREG_SET_ORDER);
        foreach ($m as $link) {
            $links[] = ['href' => $link[2], 'text' => trim($link[1])];
        }
        preg_match_all('/<img\b[^>]*>/i', $body, $m);
        foreach ($m[0] as $tag) {
            $images[] = ['src' => self::attr($tag, 'src'), 'alt' => self::attr($tag, 'alt')];
        }

        $text = implode("\n", $paragraphs);

        return new ContentSummary($headings, array_values(array_filter($paragraphs, static fn(string $p) => '' !== $p)), $images, $links, $text, ContentSummary::countWords($text), $startsWithParagraph ?? false);
    }

    private function html(string $body): ContentSummary
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><div id="__root">' . $body . '</div>', \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementById('__root');
        $headings = $paragraphs = $images = $links = [];
        $startsWithParagraph = null;
        $text = '';
        if (null !== $root) {
            foreach ($root->childNodes as $node) {
                if ($node instanceof \DOMElement && '' !== trim($node->textContent)) {
                    $startsWithParagraph ??= 'p' === strtolower($node->tagName);
                }
            }
            foreach ($root->getElementsByTagName('*') as $el) {
                $tag = strtolower($el->tagName);
                if (preg_match('/^h([1-6])$/', $tag, $m)) {
                    $headings[] = ['level' => (int) $m[1], 'text' => self::squash($el->textContent)];
                } elseif ('p' === $tag) {
                    $p = self::squash($el->textContent);
                    if ('' !== $p) {
                        $paragraphs[] = $p;
                    }
                } elseif ('img' === $tag) {
                    $images[] = ['src' => $el->getAttribute('src'), 'alt' => trim($el->getAttribute('alt'))];
                } elseif ('a' === $tag) {
                    $links[] = ['href' => $el->getAttribute('href'), 'text' => self::squash($el->textContent)];
                }
            }
            foreach ($root->getElementsByTagName('pre') as $pre) {
                $pre->parentNode?->removeChild($pre);
            }
            $text = self::squash($root->textContent);
        }

        return new ContentSummary($headings, $paragraphs, $images, $links, $text, ContentSummary::countWords($text), $startsWithParagraph ?? false);
    }

    /** Markdown inline markup to plain text. */
    private static function inline(string $s): string
    {
        $s = (string) preg_replace('/!\[([^\]]*)\]\([^)]*\)/', '', $s);
        $s = (string) preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $s);
        $s = (string) preg_replace('/`([^`]*)`/', '$1', $s);
        $s = (string) preg_replace('/(\*\*|__|\*|_|~~)(.+?)\1/', '$2', $s);
        $s = (string) preg_replace('/^([-*+]|\d+[.)]|>)[ \t]+/m', '', $s);
        $s = strip_tags($s);

        return self::squash($s);
    }

    private static function squash(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    private static function attr(string $tag, string $name): string
    {
        return preg_match('/\b' . $name . '\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $tag, $m) ? trim($m[2] . ($m[3] ?? '')) : '';
    }
}
