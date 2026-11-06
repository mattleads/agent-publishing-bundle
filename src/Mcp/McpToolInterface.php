<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Mcp;

use MattLeads\AgentPublishingBundle\Model\ApiKey;
use MattLeads\AgentPublishingBundle\Model\Scope;

/**
 * One MCP tool. Register your own as a service tagged "mattleads_agent_publishing.mcp_tool".
 */
interface McpToolInterface
{
    public function name(): string;

    public function description(): string;

    /** @return array<string, mixed> JSON Schema of the arguments object */
    public function inputSchema(): array;

    /** Scope the calling key must hold; the tool is hidden from keys without it. */
    public function scope(): Scope;

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed> JSON-serialisable result, returned to the agent as structured content
     *
     * @throws ToolException for errors the agent should see and act on
     */
    public function call(array $arguments, ApiKey $key): array;
}
