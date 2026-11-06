<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Mcp\Tool;

use MattLeads\AgentPublishingBundle\Api\ArticleNormalizer;
use MattLeads\AgentPublishingBundle\Contract\ArticleStorageInterface;
use MattLeads\AgentPublishingBundle\Model\ApiKey;
use MattLeads\AgentPublishingBundle\Model\Scope;

final class GetArticleTool extends AbstractTool
{
    public function __construct(private readonly ArticleStorageInterface $storage, private readonly ArticleNormalizer $normalizer) {}

    public function name(): string
    {
        return 'get_article';
    }

    public function description(): string
    {
        return 'Read one article in full, including its body, state, review note and the transitions allowed from its state.';
    }

    public function inputSchema(): array
    {
        return self::schema(['id' => ['type' => 'string', 'description' => 'Article id.']], ['id']);
    }

    public function scope(): Scope
    {
        return Scope::ArticlesRead;
    }

    public function call(array $arguments, ApiKey $key): array
    {
        return $this->normalizer->normalize(self::article($this->storage, (string) self::string($arguments, 'id', true), $key));
    }
}
