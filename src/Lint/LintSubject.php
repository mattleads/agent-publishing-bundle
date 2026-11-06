<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Lint;

use MattLeads\AgentPublishingBundle\Lint\Content\ContentInspector;
use MattLeads\AgentPublishingBundle\Lint\Content\ContentSummary;
use MattLeads\AgentPublishingBundle\Model\ArticleInput;
use MattLeads\AgentPublishingBundle\Model\ArticleInterface;
use MattLeads\AgentPublishingBundle\Model\Section;

/**
 * The article under inspection, stored or not yet created.
 */
final class LintSubject
{
    private ?ContentSummary $content = null;

    /** @param list<string> $tags */
    public function __construct(
        public readonly Section $section,
        public readonly string $locale,
        public readonly string $title,
        public readonly ?string $slug,
        public readonly ?string $summary,
        public readonly string $body,
        public readonly string $format,
        public readonly array $tags,
        public readonly ?string $translationGroup,
        public readonly ?string $articleId,
        private readonly ContentInspector $inspector,
    ) {}

    public static function fromArticle(ArticleInterface $article, Section $section, ContentInspector $inspector): self
    {
        return new self($section, $article->getLocale(), $article->getTitle(), $article->getSlug(), $article->getSummary(), $article->getBody(), $article->getFormat(), $article->getTags(), $article->getTranslationGroup(), $article->getId(), $inspector);
    }

    public static function fromInput(ArticleInput $input, Section $section, ContentInspector $inspector): self
    {
        return new self($section, (string) $input->locale, (string) $input->title, $input->slug, $input->summary, (string) $input->body, $input->format ?? 'markdown', $input->tags ?? [], $input->translationGroup, null, $inspector);
    }

    public function content(): ContentSummary
    {
        return $this->content ??= $this->inspector->inspect($this->body, $this->format);
    }

    /**
     * Lower-cased words of at least four letters from the title, the terms a reader searched for.
     *
     * @return list<string>
     */
    public function titleTerms(): array
    {
        preg_match_all('/\p{L}{4,}/u', mb_strtolower($this->title), $m);

        return array_values(array_unique($m[0]));
    }
}
