<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Mcp;

/** A tool-level failure: reported to the agent with isError, not as a protocol error. */
final class ToolException extends \RuntimeException
{
    /** @param list<string> $details */
    public function __construct(string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
