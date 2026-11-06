<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Mcp\Tool;

use MattLeads\AgentPublishingBundle\Model\ApiKey;
use MattLeads\AgentPublishingBundle\Model\Scope;

/** Returns the site's writing rules from configuration so an agent writes in the site's voice. */
final class StyleGuideTool extends AbstractTool
{
    public function __construct(private readonly ?string $styleGuide) {}

    public function name(): string
    {
        return 'get_style_guide';
    }

    public function description(): string
    {
        return 'The writing rules of this site: voice, structure, what to avoid. Read it before drafting.';
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
        return ['style_guide' => $this->styleGuide ?? 'No style guide configured. Write clearly, lead with the answer, name exact versions, and keep the opening paragraph quotable.'];
    }
}
