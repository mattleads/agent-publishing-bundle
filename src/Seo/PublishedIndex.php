<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Seo;

use MattLeads\AgentPublishingBundle\Contract\ArticleStorageInterface;
use MattLeads\AgentPublishingBundle\Model\ArticleFilter;
use MattLeads\AgentPublishingBundle\Model\ArticleInterface;
use MattLeads\AgentPublishingBundle\Model\ArticleState;

/**
 * Reads every published article, page by page, for the sitemap and llms.txt.
 */
final class PublishedIndex
{
    public function __construct(private readonly ArticleStorageInterface $storage) {}

    /** @return list<ArticleInterface> newest updated first */
    public function all(?string $section = null, ?string $locale = null): array
    {
        $articles = [];
        for ($page = 1;; ++$page) {
            $batch = $this->storage->list(new ArticleFilter($section, $locale, ArticleState::Published, $page, ArticleFilter::MAX_PER_PAGE));
            array_push($articles, ...$batch);
            if (\count($batch) < ArticleFilter::MAX_PER_PAGE) {
                return $articles;
            }
        }
    }

    /**
     * Translations of each article, keyed by translation group. Articles without a group are not included.
     *
     * @param list<ArticleInterface> $articles
     *
     * @return array<string, list<ArticleInterface>>
     */
    public static function groups(array $articles): array
    {
        $groups = [];
        foreach ($articles as $article) {
            if (null !== $group = $article->getTranslationGroup()) {
                $groups[$group][] = $article;
            }
        }

        return $groups;
    }
}
