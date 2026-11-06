<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Scheduler;

use MattLeads\AgentPublishingBundle\Contract\ArticleStorageInterface;
use MattLeads\AgentPublishingBundle\Model\ArticleFilter;
use MattLeads\AgentPublishingBundle\Model\ArticleInterface;
use MattLeads\AgentPublishingBundle\Model\ArticleState;
use MattLeads\AgentPublishingBundle\Workflow\ArticleWorkflow;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Publishes articles whose "publish_at" has passed. Run it from cron through the
 * agent-publishing:publish-scheduled command, or let the bundle register it as a Symfony Scheduler task.
 */
final class ScheduledPublisher
{
    public const ACTOR = 'scheduler';

    public function __construct(
        private readonly ArticleStorageInterface $storage,
        private readonly ArticleWorkflow $workflow,
        private readonly ?ClockInterface $clock = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /** @return list<ArticleInterface> the articles that were published */
    public function __invoke(): array
    {
        return $this->publishDue();
    }

    /** @return list<ArticleInterface> the articles that were published */
    public function publishDue(): array
    {
        $now = $this->clock?->now() ?? new \DateTimeImmutable();

        // Collect first, then transition: publishing changes the state, which would shift the pages.
        $due = [];
        for ($page = 1;; ++$page) {
            $batch = $this->storage->list(new ArticleFilter(state: ArticleState::Scheduled, page: $page, perPage: ArticleFilter::MAX_PER_PAGE));
            foreach ($batch as $article) {
                $publishAt = $article->getPublishAt();
                if (null !== $publishAt && $publishAt <= $now) {
                    $due[] = $article;
                }
            }
            if (\count($batch) < ArticleFilter::MAX_PER_PAGE) {
                break;
            }
        }

        $published = [];
        foreach ($due as $article) {
            $published[] = $this->workflow->apply($article, 'publish', actor: self::ACTOR);
            $this->logger?->info('Published scheduled article {id} "{title}" ({section}/{locale}).', [
                'id' => $article->getId(), 'title' => $article->getTitle(), 'section' => $article->getSection(), 'locale' => $article->getLocale(),
            ]);
        }

        return $published;
    }
}
