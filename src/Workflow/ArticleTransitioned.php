<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Workflow;

use MattLeads\AgentPublishingBundle\Model\ArticleInterface;
use MattLeads\AgentPublishingBundle\Model\ArticleState;

/**
 * Dispatched after every successful transition. Listen to it to warm caches, notify reviewers or ping a search engine.
 */
final class ArticleTransitioned
{
    public function __construct(
        public readonly ArticleInterface $article,
        public readonly string $transition,
        public readonly ArticleState $from,
        public readonly ArticleState $to,
        public readonly ?string $actor,
    ) {}
}
