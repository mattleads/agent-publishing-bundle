<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Storage;

use MattLeads\AgentPublishingBundle\Contract\ArticleStorageInterface;
use MattLeads\AgentPublishingBundle\Model\Article;
use MattLeads\AgentPublishingBundle\Model\ArticleFilter;
use MattLeads\AgentPublishingBundle\Model\ArticleInput;
use MattLeads\AgentPublishingBundle\Model\ArticleInterface;
use MattLeads\AgentPublishingBundle\Model\ArticleState;
use Symfony\Component\Uid\Ulid;

/**
 * Non-persistent storage for tests and prototypes.
 */
final class InMemoryArticleStorage implements ArticleStorageInterface
{
    /** @var array<string, Article> */
    private array $articles = [];

    /** @var array<string, string> "section|locale|slug" => article id, in the order the slugs were retired */
    private array $formerSlugs = [];

    public function find(string $id): ?ArticleInterface
    {
        return $this->articles[$id] ?? null;
    }

    public function list(ArticleFilter $filter): array
    {
        $matching = array_values(array_filter($this->articles, static fn(Article $a) => $filter->matches($a)));
        $date = 'published' === $filter->sort
            ? static fn(Article $a) => $a->getPublishedAt()
            : static fn(Article $a) => $a->getUpdatedAt();
        usort($matching, static fn(Article $a, Article $b) => $date($b) <=> $date($a) ?: strcmp($b->getId(), $a->getId()));

        return \array_slice($matching, $filter->offset(), $filter->perPage);
    }

    public function count(ArticleFilter $filter): int
    {
        return \count(array_filter($this->articles, static fn(Article $a) => $filter->matches($a)));
    }

    public function slugExists(string $section, string $locale, string $slug, ?string $exceptId = null): bool
    {
        foreach ($this->articles as $article) {
            if ($article->getId() !== $exceptId && $article->getSection() === $section && $article->getLocale() === $locale && $article->getSlug() === $slug) {
                return true;
            }
        }

        return false;
    }

    public function findBySlug(string $section, string $locale, string $slug): ?ArticleInterface
    {
        foreach ($this->articles as $article) {
            if ($article->getSection() === $section && $article->getLocale() === $locale && $article->getSlug() === $slug) {
                return $article;
            }
        }

        return null;
    }

    public function findByFormerSlug(string $section, string $locale, string $slug): ?ArticleInterface
    {
        $id = $this->formerSlugs[self::slugKey($section, $locale, $slug)] ?? null;

        return null === $id ? null : $this->find($id);
    }

    public function formerSlugs(ArticleInterface $article): array
    {
        $slugs = [];
        foreach ($this->formerSlugs as $key => $id) {
            if ($id === $article->getId()) {
                $slugs[] = explode('|', $key, 3)[2];
            }
        }

        return $slugs;
    }

    public function create(ArticleInput $input, string $slug, ?string $createdBy, \DateTimeImmutable $now): ArticleInterface
    {
        $article = new Article((string) new Ulid(), (string) $input->section, (string) $input->locale, (string) $input->title, $slug, (string) $input->body, $input->format ?? 'markdown', $now, $createdBy);
        $article->apply($input, null, $now);
        unset($this->formerSlugs[self::slugKey($article->getSection(), $article->getLocale(), $slug)]);
        $this->articles[$article->getId()] = $article;

        return $article;
    }

    public function update(ArticleInterface $article, ArticleInput $input, ?string $slug, \DateTimeImmutable $now): ArticleInterface
    {
        $stored = $this->own($article);
        $previous = $stored->getSlug();
        if (null !== $slug && $slug !== $previous) {
            unset($this->formerSlugs[self::slugKey($stored->getSection(), $stored->getLocale(), $slug)]);
            if (\in_array($stored->getState(), [ArticleState::Published, ArticleState::Scheduled], true)) {
                $this->formerSlugs[self::slugKey($stored->getSection(), $stored->getLocale(), $previous)] = $stored->getId();
            }
        }
        $stored->apply($input, $slug, $now);

        return $stored;
    }

    public function transition(ArticleInterface $article, ArticleState $to, \DateTimeImmutable $now, ?string $note = null, ?\DateTimeImmutable $publishAt = null): ArticleInterface
    {
        $stored = $this->own($article);
        $stored->transitionTo($to, $now, $note, $publishAt);

        return $stored;
    }

    private static function slugKey(string $section, string $locale, string $slug): string
    {
        return $section . '|' . $locale . '|' . $slug;
    }

    private function own(ArticleInterface $article): Article
    {
        return $this->articles[$article->getId()] ?? throw new \LogicException(\sprintf('Article "%s" is not in this storage.', $article->getId()));
    }
}
