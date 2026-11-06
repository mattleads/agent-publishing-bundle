<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Api;

use MattLeads\AgentPublishingBundle\Model\ArticleInterface;
use MattLeads\AgentPublishingBundle\Workflow\ArticleWorkflow;

final class ArticleNormalizer
{
    public function __construct(private readonly ArticleWorkflow $workflow) {}

    /** @return array<string, mixed> */
    public function normalize(ArticleInterface $article): array
    {
        return [
            'id' => $article->getId(),
            'section' => $article->getSection(),
            'locale' => $article->getLocale(),
            'title' => $article->getTitle(),
            'slug' => $article->getSlug(),
            'summary' => $article->getSummary(),
            'body' => $article->getBody(),
            'format' => $article->getFormat(),
            'tags' => $article->getTags(),
            'translation_group' => $article->getTranslationGroup(),
            'state' => $article->getState()->value,
            'transitions' => array_values(array_filter(ArticleWorkflow::transitions(), fn(string $t) => $this->workflow->can($article, $t))),
            'review_note' => $article->getReviewNote(),
            'created_by' => $article->getCreatedBy(),
            'created_at' => $article->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $article->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'published_at' => $article->getPublishedAt()?->format(\DateTimeInterface::ATOM),
            'publish_at' => $article->getPublishAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
