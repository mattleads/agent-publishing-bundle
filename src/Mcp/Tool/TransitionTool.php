<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Mcp\Tool;

use MattLeads\AgentPublishingBundle\Api\ArticleNormalizer;
use MattLeads\AgentPublishingBundle\Contract\ArticleStorageInterface;
use MattLeads\AgentPublishingBundle\Mcp\ToolException;
use MattLeads\AgentPublishingBundle\Model\ApiKey;
use MattLeads\AgentPublishingBundle\Model\Scope;
use MattLeads\AgentPublishingBundle\Workflow\ArticleWorkflow;
use MattLeads\AgentPublishingBundle\Workflow\InvalidTransitionException;

/** One instance per workflow transition: submit_article, approve_article, reject_article, publish_article, archive_article. */
final class TransitionTool extends AbstractTool
{
    private const DESCRIPTIONS = [
        'submit' => 'Submit a draft for human review (draft → in_review). Lint it first.',
        'approve' => 'Approve an article in review and publish it (in_review → published).',
        'reject' => 'Send an article in review back to draft with a reason the author will see (in_review → draft).',
        'publish' => 'Publish now, or schedule it with publish_at in the future (→ published or scheduled). Needs the articles:publish scope.',
        'archive' => 'Archive a published or scheduled article so it is no longer listed (→ archived).',
    ];

    public function __construct(
        private readonly string $transition,
        private readonly ArticleStorageInterface $storage,
        private readonly ArticleWorkflow $workflow,
        private readonly ArticleNormalizer $normalizer,
    ) {
        if (!isset(self::DESCRIPTIONS[$transition])) {
            throw new \InvalidArgumentException(\sprintf('Unknown transition "%s".', $transition));
        }
    }

    public function name(): string
    {
        return $this->transition . '_article';
    }

    public function description(): string
    {
        return self::DESCRIPTIONS[$this->transition];
    }

    public function inputSchema(): array
    {
        $properties = ['id' => ['type' => 'string']];
        if ('reject' === $this->transition) {
            $properties['reason'] = ['type' => 'string', 'description' => 'Why it was rejected; stored as review_note.'];
        }
        if ('publish' === $this->transition) {
            $properties['publish_at'] = ['type' => 'string', 'format' => 'date-time', 'description' => 'When in the future, the article is scheduled instead of published.'];
        }

        return self::schema($properties, ['id']);
    }

    public function scope(): Scope
    {
        return ArticleWorkflow::scopeFor($this->transition);
    }

    public function call(array $arguments, ApiKey $key): array
    {
        $article = self::article($this->storage, (string) self::string($arguments, 'id', true), $key);
        $publishAt = null;
        if (null !== $raw = self::string($arguments, 'publish_at')) {
            try {
                $publishAt = new \DateTimeImmutable($raw);
            } catch (\Exception) {
                throw new ToolException('Argument "publish_at" must be an ISO 8601 date-time.');
            }
        }
        try {
            $article = $this->workflow->apply($article, $this->transition, self::string($arguments, 'reason'), $publishAt, $key->getName());
        } catch (InvalidTransitionException $e) {
            throw new ToolException($e->getMessage());
        }

        return $this->normalizer->normalize($article);
    }
}
