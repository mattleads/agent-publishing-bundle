<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Model;

final class ValidationException extends \InvalidArgumentException
{
    /** @param list<string> $violations */
    public function __construct(public readonly array $violations)
    {
        parent::__construct(implode(' ', $violations));
    }
}
