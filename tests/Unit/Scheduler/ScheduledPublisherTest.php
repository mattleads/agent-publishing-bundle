<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Tests\Unit\Scheduler;

use MattLeads\AgentPublishingBundle\Model\ArticleInput;
use MattLeads\AgentPublishingBundle\Model\ArticleInterface;
use MattLeads\AgentPublishingBundle\Model\ArticleState;
use MattLeads\AgentPublishingBundle\Scheduler\ScheduledPublisher;
use MattLeads\AgentPublishingBundle\Storage\InMemoryArticleStorage;
use MattLeads\AgentPublishingBundle\Workflow\ArticleTransitioned;
use MattLeads\AgentPublishingBundle\Workflow\ArticleWorkflow;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class ScheduledPublisherTest extends TestCase
{
    public function testPublishesOnlyDueScheduledArticles(): void
    {
        $storage = new InMemoryArticleStorage();
        $clock = new MockClock('2026-11-06 10:00:00', 'UTC');
        $dispatcher = new EventDispatcher();
        $workflow = new ArticleWorkflow($storage, $dispatcher, $clock);
        $publisher = new ScheduledPublisher($storage, $workflow, $clock);

        $draft = $this->draft($storage, $clock, 'draft');
        $due = $workflow->apply($this->draft($storage, $clock, 'due'), 'publish', publishAt: new \DateTimeImmutable('2026-11-06 12:00:00', new \DateTimeZone('UTC')));
        $later = $workflow->apply($this->draft($storage, $clock, 'later'), 'publish', publishAt: new \DateTimeImmutable('2026-11-07 12:00:00', new \DateTimeZone('UTC')));
        self::assertSame(ArticleState::Scheduled, $due->getState());

        self::assertSame([], $publisher->publishDue(), 'nothing is due yet');

        $events = [];
        $dispatcher->addListener(ArticleTransitioned::class, static function (ArticleTransitioned $e) use (&$events): void {
            $events[] = $e;
        });
        $clock->modify('+2 hours');
        $published = $publisher->publishDue();

        self::assertSame([$due->getId()], array_map(static fn(ArticleInterface $a) => $a->getId(), $published));
        $dueNow = $storage->find($due->getId());
        self::assertNotNull($dueNow);
        self::assertSame(ArticleState::Published, $dueNow->getState());
        self::assertEquals($clock->now(), $dueNow->getPublishedAt());
        self::assertSame(ArticleState::Scheduled, $storage->find($later->getId())?->getState());
        self::assertSame(ArticleState::Draft, $storage->find($draft->getId())?->getState());
        self::assertCount(1, $events);
        self::assertSame('scheduler', $events[0]->actor);

        self::assertSame([], $publisher->publishDue(), 'idempotent');
    }

    public function testPagesThroughMoreScheduledArticlesThanOnePage(): void
    {
        $storage = new InMemoryArticleStorage();
        $clock = new MockClock('2026-11-06 10:00:00', 'UTC');
        $workflow = new ArticleWorkflow($storage, new EventDispatcher(), $clock);
        $publisher = new ScheduledPublisher($storage, $workflow, $clock);

        for ($i = 0; $i < 105; ++$i) {
            $workflow->apply($this->draft($storage, $clock, 'n' . $i), 'publish', publishAt: new \DateTimeImmutable('2026-11-06 11:00:00', new \DateTimeZone('UTC')));
        }
        $clock->modify('+1 day');

        self::assertCount(105, $publisher->publishDue());
        self::assertSame([], $publisher->publishDue());
    }

    private function draft(InMemoryArticleStorage $storage, MockClock $clock, string $slug): ArticleInterface
    {
        return $storage->create(ArticleInput::fromArray(['section' => 'blog', 'locale' => 'en', 'title' => $slug, 'body' => 'B']), $slug, 'agent', $clock->now());
    }
}
