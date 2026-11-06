<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Tests\Unit\Workflow;

use MattLeads\AgentPublishingBundle\Model\ArticleInput;
use MattLeads\AgentPublishingBundle\Model\ArticleInterface;
use MattLeads\AgentPublishingBundle\Model\ArticleState;
use MattLeads\AgentPublishingBundle\Model\Scope;
use MattLeads\AgentPublishingBundle\Storage\InMemoryArticleStorage;
use MattLeads\AgentPublishingBundle\Workflow\ArticleTransitioned;
use MattLeads\AgentPublishingBundle\Workflow\ArticleWorkflow;
use MattLeads\AgentPublishingBundle\Workflow\InvalidTransitionException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class ArticleWorkflowTest extends TestCase
{
    private InMemoryArticleStorage $storage;
    private EventDispatcher $dispatcher;
    private MockClock $clock;
    private ArticleWorkflow $workflow;

    protected function setUp(): void
    {
        $this->storage = new InMemoryArticleStorage();
        $this->dispatcher = new EventDispatcher();
        $this->clock = new MockClock('2026-11-06 10:00:00', 'UTC');
        $this->workflow = new ArticleWorkflow($this->storage, $this->dispatcher, $this->clock);
    }

    public function testSubmitRejectSubmitApprove(): void
    {
        $article = $this->draft();
        $events = [];
        $this->dispatcher->addListener(ArticleTransitioned::class, static function (ArticleTransitioned $e) use (&$events): void {
            $events[] = $e;
        });

        $article = $this->workflow->apply($article, 'submit', actor: 'agent');
        self::assertSame(ArticleState::InReview, $article->getState());

        $article = $this->workflow->apply($article, 'reject', note: 'Too short');
        self::assertSame(ArticleState::Draft, $article->getState());
        self::assertSame('Too short', $article->getReviewNote());

        $this->clock->modify('+1 hour');
        $article = $this->workflow->apply($article, 'submit');
        $article = $this->workflow->apply($article, 'approve', note: 'ignored outside reject');
        self::assertSame(ArticleState::Published, $article->getState());
        self::assertNull($article->getReviewNote());
        self::assertEquals(new \DateTimeImmutable('2026-11-06 11:00:00', new \DateTimeZone('UTC')), $article->getPublishedAt());

        self::assertCount(4, $events);
        self::assertSame(['submit', 'reject', 'submit', 'approve'], array_map(static fn(ArticleTransitioned $e) => $e->transition, $events));
        self::assertSame('agent', $events[0]->actor);
        self::assertSame(ArticleState::Draft, $events[0]->from);
        self::assertSame(ArticleState::InReview, $events[0]->to);
    }

    public function testPublishInTheFutureSchedulesAndPublishNowPublishes(): void
    {
        $article = $this->draft();
        $article = $this->workflow->apply($article, 'publish', publishAt: new \DateTimeImmutable('2026-12-01 09:00:00', new \DateTimeZone('UTC')));
        self::assertSame(ArticleState::Scheduled, $article->getState());
        self::assertEquals(new \DateTimeImmutable('2026-12-01 09:00:00', new \DateTimeZone('UTC')), $article->getPublishAt());
        self::assertNull($article->getPublishedAt());

        $article = $this->workflow->apply($article, 'publish');
        self::assertSame(ArticleState::Published, $article->getState());
        self::assertNull($article->getPublishAt());

        $past = $this->workflow->apply($this->draft(), 'publish', publishAt: new \DateTimeImmutable('2020-01-01'));
        self::assertSame(ArticleState::Published, $past->getState(), 'a past publish_at publishes immediately');
    }

    public function testInvalidTransitions(): void
    {
        $article = $this->draft();
        self::assertFalse($this->workflow->can($article, 'approve'));
        self::assertTrue($this->workflow->can($article, 'submit'));

        $this->expectException(InvalidTransitionException::class);
        $this->expectExceptionMessage('Cannot "approve" an article in state "draft".');
        $this->workflow->apply($article, 'approve');
    }

    public function testUnknownTransition(): void
    {
        $this->expectException(InvalidTransitionException::class);
        $this->workflow->apply($this->draft(), 'teleport');
    }

    public function testScopes(): void
    {
        self::assertSame(Scope::ArticlesDraft, ArticleWorkflow::scopeFor('submit'));
        self::assertSame(Scope::ArticlesApprove, ArticleWorkflow::scopeFor('approve'));
        self::assertSame(Scope::ArticlesApprove, ArticleWorkflow::scopeFor('reject'));
        self::assertSame(Scope::ArticlesPublish, ArticleWorkflow::scopeFor('publish'));
        self::assertSame(Scope::ArticlesPublish, ArticleWorkflow::scopeFor('archive'));
    }

    private function draft(): ArticleInterface
    {
        return $this->storage->create(ArticleInput::fromArray(['section' => 'blog', 'locale' => 'en', 'title' => 'T', 'body' => 'B']), 't', 'agent', $this->clock->now());
    }
}
