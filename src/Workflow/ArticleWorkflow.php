<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Workflow;

use MattLeads\AgentPublishingBundle\Contract\ArticleStorageInterface;
use MattLeads\AgentPublishingBundle\Model\ArticleInterface;
use MattLeads\AgentPublishingBundle\Model\ArticleState;
use MattLeads\AgentPublishingBundle\Model\Scope;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

final class ArticleWorkflow
{
    /** @var array<string, array{from: list<ArticleState>, to: ArticleState, scope: Scope}> */
    public const TRANSITIONS = [
        'submit' => ['from' => [ArticleState::Draft], 'to' => ArticleState::InReview, 'scope' => Scope::ArticlesDraft],
        'approve' => ['from' => [ArticleState::InReview], 'to' => ArticleState::Published, 'scope' => Scope::ArticlesApprove],
        'reject' => ['from' => [ArticleState::InReview], 'to' => ArticleState::Draft, 'scope' => Scope::ArticlesApprove],
        'publish' => ['from' => [ArticleState::Draft, ArticleState::InReview, ArticleState::Scheduled], 'to' => ArticleState::Published, 'scope' => Scope::ArticlesPublish],
        'archive' => ['from' => [ArticleState::Published, ArticleState::Scheduled], 'to' => ArticleState::Archived, 'scope' => Scope::ArticlesPublish],
    ];

    public function __construct(
        private readonly ArticleStorageInterface $storage,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly ?ClockInterface $clock = null,
    ) {}

    /** @return list<string> */
    public static function transitions(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    public static function scopeFor(string $transition): Scope
    {
        return (self::TRANSITIONS[$transition] ?? throw new InvalidTransitionException(\sprintf('Unknown transition "%s".', $transition)))['scope'];
    }

    public function can(ArticleInterface $article, string $transition): bool
    {
        return isset(self::TRANSITIONS[$transition]) && \in_array($article->getState(), self::TRANSITIONS[$transition]['from'], true);
    }

    /**
     * @throws InvalidTransitionException
     */
    public function apply(ArticleInterface $article, string $transition, ?string $note = null, ?\DateTimeImmutable $publishAt = null, ?string $actor = null): ArticleInterface
    {
        if (!isset(self::TRANSITIONS[$transition])) {
            throw new InvalidTransitionException(\sprintf('Unknown transition "%s".', $transition));
        }
        $from = $article->getState();
        if (!$this->can($article, $transition)) {
            throw new InvalidTransitionException(\sprintf('Cannot "%s" an article in state "%s".', $transition, $from->value));
        }

        $now = $this->clock?->now() ?? new \DateTimeImmutable();
        $to = self::TRANSITIONS[$transition]['to'];
        if ('publish' === $transition && null !== $publishAt && $publishAt > $now) {
            $to = ArticleState::Scheduled;
        } else {
            $publishAt = null;
        }
        if ('reject' !== $transition) {
            $note = null;
        }

        $article = $this->storage->transition($article, $to, $now, $note, $publishAt);
        $this->dispatcher->dispatch(new ArticleTransitioned($article, $transition, $from, $to, $actor));

        return $article;
    }
}
