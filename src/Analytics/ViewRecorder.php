<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Analytics;

use MattLeads\AgentPublishingBundle\Model\ArticleInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Entry point for counting a view. Call record() from your controller, or let ArticleViewListener do it from a route
 * default. Dispatches through Messenger when a bus is configured, otherwise counts synchronously.
 */
final class ViewRecorder
{
    public function __construct(
        private readonly VisitClassifier $classifier,
        private readonly ArticleViewedHandler $handler,
        private readonly ?MessageBusInterface $bus = null,
        private readonly ?ClockInterface $clock = null,
    ) {}

    public function record(ArticleInterface $article, Request $request): ArticleViewed
    {
        $visit = $this->classifier->classify($request->headers->get('User-Agent'), $request->headers->get('Referer'), $request->getHost());
        $message = new ArticleViewed($article->getId(), $article->getSection(), $this->clock?->now() ?? new \DateTimeImmutable(), $visit['kind'], $visit['host']);

        if (null !== $this->bus) {
            $this->bus->dispatch($message);
        } else {
            ($this->handler)($message);
        }

        return $message;
    }
}
