<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Mcp\Tool;

use MattLeads\AgentPublishingBundle\Api\AuthorController;
use MattLeads\AgentPublishingBundle\Author\AuthorRegistry;
use MattLeads\AgentPublishingBundle\Model\ApiKey;
use MattLeads\AgentPublishingBundle\Model\Scope;

final class ListAuthorsTool extends AbstractTool
{
    public function __construct(private readonly AuthorRegistry $authors) {}

    public function name(): string
    {
        return 'list_authors';
    }

    public function description(): string
    {
        return 'People who may be credited on articles. Pass one key as "author"; omit it to credit the publisher ("default" says who is credited then).';
    }

    public function inputSchema(): array
    {
        return self::schema([]);
    }

    public function scope(): Scope
    {
        return Scope::ArticlesRead;
    }

    public function call(array $arguments, ApiKey $key): array
    {
        return AuthorController::payload($this->authors);
    }
}
