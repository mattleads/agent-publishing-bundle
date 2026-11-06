<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Lint\Content;

/**
 * What the rules look at: structure and plain text of a body, independent of its format.
 */
final class ContentSummary
{
    /**
     * @param list<array{level: int, text: string}>   $headings   in document order
     * @param list<string>                            $paragraphs plain-text paragraphs in order, code blocks excluded
     * @param list<array{src: string, alt: string}>   $images
     * @param list<array{href: string, text: string}> $links
     */
    public function __construct(
        public readonly array $headings,
        public readonly array $paragraphs,
        public readonly array $images,
        public readonly array $links,
        public readonly string $text,
        public readonly int $words,
        public readonly bool $startsWithParagraph,
    ) {}

    public function firstParagraph(): ?string
    {
        return $this->paragraphs[0] ?? null;
    }

    /** @return list<array{level: int, text: string}> */
    public function headingsOfLevel(int $level): array
    {
        return array_values(array_filter($this->headings, static fn(array $h) => $h['level'] === $level));
    }

    public static function countWords(string $text): int
    {
        return \count(preg_split('/\s+/u', trim($text), -1, \PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
