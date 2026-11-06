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

    public function find(string $id): ?ArticleInterface
    {
        return $this->articles[$id] ?? null;
    }

    public function list(ArticleFilter $filter): array
    {
        $matching = array_values(array_filter($this->articles, static fn(Article $a) => $filter->matches($a)));
        usort($matching, static fn(Article $a, Article $b) => $b->getUpdatedAt() <=> $a->getUpdatedAt() ?: strcmp($b->getId(), $a->getId()));

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

    public function create(ArticleInput $input, string $slug, ?string $createdBy, \DateTimeImmutable $now): ArticleInterface
    {
        $article = new Article((string) new Ulid(), (string) $input->section, (string) $input->locale, (string) $input->title, $slug, (string) $input->body, $input->format ?? 'markdown', $now, $createdBy);
        $article->apply($input, null, $now);
        $this->articles[$article->getId()] = $article;

        return $article;
    }

    public function update(ArticleInterface $article, ArticleInput $input, ?string $slug, \DateTimeImmutable $now): ArticleInterface
    {
        $stored = $this->own($article);
        $stored->apply($input, $slug, $now);

        return $stored;
    }

    public function transition(ArticleInterface $article, ArticleState $to, \DateTimeImmutable $now, ?string $note = null, ?\DateTimeImmutable $publishAt = null): ArticleInterface
    {
        $stored = $this->own($article);
        $stored->transitionTo($to, $now, $note, $publishAt);

        return $stored;
    }

    private function own(ArticleInterface $article): Article
    {
        return $this->articles[$article->getId()] ?? throw new \LogicException(\sprintf('Article "%s" is not in this storage.', $article->getId()));
    }
}
