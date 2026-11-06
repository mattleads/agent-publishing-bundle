<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Mcp\Tool;

use MattLeads\AgentPublishingBundle\Api\ArticleNormalizer;
use MattLeads\AgentPublishingBundle\Contract\ArticleStorageInterface;
use MattLeads\AgentPublishingBundle\Mcp\ToolException;
use MattLeads\AgentPublishingBundle\Model\ApiKey;
use MattLeads\AgentPublishingBundle\Model\ArticleFilter;
use MattLeads\AgentPublishingBundle\Model\ArticleInterface;
use MattLeads\AgentPublishingBundle\Model\ArticleState;
use MattLeads\AgentPublishingBundle\Model\Scope;
use MattLeads\AgentPublishingBundle\Model\ValidationException;

final class ListArticlesTool extends AbstractTool
{
    public function __construct(private readonly ArticleStorageInterface $storage, private readonly ArticleNormalizer $normalizer) {}

    public function name(): string
    {
        return 'list_articles';
    }

    public function description(): string
    {
        return 'List articles, newest updated first (sort "published" for newest published first). Filter by section, locale and state (draft, in_review, scheduled, published, archived). Bodies are omitted; use get_article for one.';
    }

    public function inputSchema(): array
    {
        return self::schema([
            'section' => ['type' => 'string'],
            'locale' => ['type' => 'string'],
            'state' => ['type' => 'string', 'enum' => array_map(static fn(ArticleState $s) => $s->value, ArticleState::cases())],
            'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => ArticleFilter::MAX_PER_PAGE, 'default' => 20],
            'sort' => ['type' => 'string', 'enum' => ArticleFilter::SORTS, 'default' => 'updated'],
        ]);
    }

    public function scope(): Scope
    {
        return Scope::ArticlesRead;
    }

    public function call(array $arguments, ApiKey $key): array
    {
        $section = self::string($arguments, 'section');
        if (null === $section && 1 === \count($key->getSections())) {
            $section = $key->getSections()[0];
        } elseif (null === $section && [] !== $key->getSections()) {
            throw new ToolException(\sprintf('This key is restricted to sections %s; pass "section".', implode(', ', $key->getSections())));
        }
        self::allowSection($key, $section);
        $state = self::string($arguments, 'state');
        if (null !== $state && null === ArticleState::tryFrom($state)) {
            throw new ToolException(\sprintf('Unknown state "%s".', $state));
        }
        try {
            $filter = new ArticleFilter($section, self::string($arguments, 'locale'), null === $state ? null : ArticleState::from($state), self::int($arguments, 'page', 1), self::int($arguments, 'per_page', 20), self::string($arguments, 'sort') ?? 'updated');
        } catch (ValidationException $e) {
            throw self::invalid($e);
        }

        return [
            'items' => array_map(fn(ArticleInterface $a) => array_diff_key($this->normalizer->normalize($a), ['body' => 1]), $this->storage->list($filter)),
            'total' => $this->storage->count($filter),
            'page' => $filter->page,
            'per_page' => $filter->perPage,
        ];
    }
}
