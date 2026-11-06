<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Author;

use MattLeads\AgentPublishingBundle\Model\ArticleInterface;
use MattLeads\AgentPublishingBundle\Model\Author;

final class AuthorRegistry
{
    public const ORGANIZATION = 'organization';

    /** @var array<string, Author> */
    private array $authors = [];

    /**
     * @param array<string, array{name: string, url: ?string, image: ?string, bio: ?string, same_as: list<string>}> $authors
     * @param string                                                                                                $defaultAuthor an author key, or "organization" for the publisher
     */
    public function __construct(array $authors = [], private readonly string $defaultAuthor = self::ORGANIZATION)
    {
        foreach ($authors as $key => $definition) {
            $this->authors[$key] = new Author($key, $definition['name'], $definition['url'], $definition['image'], $definition['bio'], array_values($definition['same_as']));
        }
        if (self::ORGANIZATION !== $defaultAuthor && !isset($this->authors[$defaultAuthor])) {
            throw new \InvalidArgumentException(\sprintf('mattleads_agent_publishing.seo.default_author "%s" is not a configured author.', $defaultAuthor));
        }
    }

    public function get(string $key): ?Author
    {
        return $this->authors[$key] ?? null;
    }

    /** @return list<Author> */
    public function all(): array
    {
        return array_values($this->authors);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->authors);
    }

    public function defaultAuthor(): string
    {
        return $this->defaultAuthor;
    }

    /** The person credited on an article, or null when the publisher organisation is. */
    public function resolve(ArticleInterface $article): ?Author
    {
        $key = $article->getAuthor() ?? (self::ORGANIZATION === $this->defaultAuthor ? null : $this->defaultAuthor);

        return null === $key ? null : $this->get($key);
    }
}
