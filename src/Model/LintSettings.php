<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Model;

/** Per-section expectations the lint rules read. */
final class LintSettings
{
    public function __construct(
        public readonly bool $requireFaq = false,
        public readonly int $minWords = 300,
        public readonly int $minInternalLinks = 1,
    ) {}
}
