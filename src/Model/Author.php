<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Model;

/**
 * A person who may be credited on articles. Declared in YAML, never created over the API.
 */
final class Author
{
    /** @param list<string> $sameAs */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly ?string $url = null,
        public readonly ?string $image = null,
        public readonly ?string $bio = null,
        public readonly array $sameAs = [],
    ) {}

    /** @return array{key: string, name: string, url: ?string, image: ?string, bio: ?string, same_as: list<string>} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'name' => $this->name, 'url' => $this->url, 'image' => $this->image, 'bio' => $this->bio, 'same_as' => $this->sameAs];
    }
}
