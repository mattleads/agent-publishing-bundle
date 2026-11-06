<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Workflow;

use MattLeads\AgentPublishingBundle\Author\AuthorRegistry;
use MattLeads\AgentPublishingBundle\Contract\ArticleStorageInterface;
use MattLeads\AgentPublishingBundle\Model\ArticleInput;
use MattLeads\AgentPublishingBundle\Model\ArticleInterface;
use MattLeads\AgentPublishingBundle\Model\ArticleState;
use MattLeads\AgentPublishingBundle\Model\ValidationException;
use MattLeads\AgentPublishingBundle\Section\SectionRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Creates and updates articles: checks section and locale, resolves a unique slug, then hands off to the storage.
 */
final class ArticleManager
{
    private readonly AsciiSlugger $slugger;

    public function __construct(
        private readonly ArticleStorageInterface $storage,
        private readonly SectionRegistry $sections,
        private readonly ?ClockInterface $clock = null,
        private readonly AuthorRegistry $authors = new AuthorRegistry(),
        private readonly bool $editPublished = false,
    ) {
        $this->slugger = new AsciiSlugger();
    }

    /**
     * @throws ValidationException
     */
    public function create(ArticleInput $input, ?string $createdBy = null): ArticleInterface
    {
        $section = (string) $input->section;
        $locale = (string) $input->locale;
        $this->assertSectionAndLocale($section, $locale);
        $this->assertAuthor($input);
        $slug = $this->uniqueSlug($section, $locale, $input->slug ?? (string) $input->title, null);

        return $this->storage->create($input, $slug, $createdBy, $this->now());
    }

    /**
     * @throws ValidationException
     * @throws InvalidTransitionException when the article is no longer editable
     */
    public function update(ArticleInterface $article, ArticleInput $input): ArticleInterface
    {
        if (null !== $input->section && $input->section !== $article->getSection()) {
            throw new ValidationException(['Field "section" cannot change after creation.']);
        }
        if (null !== $input->locale && $input->locale !== $article->getLocale()) {
            throw new ValidationException(['Field "locale" cannot change after creation.']);
        }
        if (!$this->isEditable($article)) {
            throw new InvalidTransitionException(\sprintf('Cannot edit an article in state "%s".', $article->getState()->value));
        }
        $this->assertAuthor($input);

        $slug = null;
        if (null !== $input->slug && $input->slug !== $article->getSlug()) {
            if ($this->storage->slugExists($article->getSection(), $article->getLocale(), $input->slug, $article->getId())) {
                throw new ValidationException([\sprintf('Slug "%s" is already used in this section and locale.', $input->slug)]);
            }
            $slug = $input->slug;
        }

        return $this->storage->update($article, $input, $slug, $this->now());
    }

    public function isEditable(ArticleInterface $article): bool
    {
        return $article->getState()->isEditable() || ($this->editPublished && self::isLive($article));
    }

    /** Published or scheduled: changes reach readers, so they need the "articles:publish" scope. */
    public static function isLive(ArticleInterface $article): bool
    {
        return \in_array($article->getState(), [ArticleState::Published, ArticleState::Scheduled], true);
    }

    private function assertAuthor(ArticleInput $input): void
    {
        if (null === $input->author || '' === $input->author || null !== $this->authors->get($input->author)) {
            return;
        }
        throw new ValidationException([[] === $this->authors->keys() ? 'Field "author" cannot be used: no authors are configured.' : \sprintf('Field "author" must be one of: %s.', implode(', ', $this->authors->keys()))]);
    }

    private function assertSectionAndLocale(string $section, string $locale): void
    {
        $definition = $this->sections->get($section);
        if (null === $definition) {
            throw new ValidationException([\sprintf('Unknown section "%s". Available: %s.', $section, implode(', ', array_map(static fn($s) => $s->name, $this->sections->all())))]);
        }
        if (!$definition->allowsLocale($locale)) {
            throw new ValidationException([\sprintf('Section "%s" does not accept locale "%s". Allowed: %s.', $section, $locale, implode(', ', $definition->locales))]);
        }
    }

    private function uniqueSlug(string $section, string $locale, string $source, ?string $exceptId): string
    {
        $base = mb_substr($this->slugger->slug($source)->lower()->toString(), 0, 190);
        if ('' === $base) {
            $base = 'article';
        }
        $slug = $base;
        for ($i = 2; $this->storage->slugExists($section, $locale, $slug, $exceptId); ++$i) {
            $slug = $base . '-' . $i;
        }

        return $slug;
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock?->now() ?? new \DateTimeImmutable();
    }
}
