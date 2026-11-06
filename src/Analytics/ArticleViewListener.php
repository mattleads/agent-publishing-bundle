<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Analytics;

use MattLeads\AgentPublishingBundle\Contract\ArticleStorageInterface;
use MattLeads\AgentPublishingBundle\Model\ArticleState;
use MattLeads\AgentPublishingBundle\Section\SectionRegistry;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

/**
 * Counts a view after the response is sent, for routes that declare which section they render:
 *
 *     #[Route('/blog/{slug}', defaults: ['_agent_publishing_section' => 'blog'])]
 *
 * The slug and locale are read from request attributes (names configurable). Only published articles count.
 */
final class ArticleViewListener
{
    public const SECTION_ATTRIBUTE = '_agent_publishing_section';

    public function __construct(
        private readonly ArticleStorageInterface $storage,
        private readonly SectionRegistry $sections,
        private readonly ViewRecorder $recorder,
        private readonly string $slugAttribute = 'slug',
        private readonly string $localeAttribute = '_locale',
    ) {}

    public function onTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $section = $request->attributes->get(self::SECTION_ATTRIBUTE);
        if (!\is_string($section) || !$event->getResponse()->isOk() || !$request->isMethod('GET')) {
            return;
        }
        $slug = $request->attributes->get($this->slugAttribute);
        if (!\is_string($slug) || '' === $slug) {
            return;
        }
        $locale = $request->attributes->get($this->localeAttribute);
        $locale = \is_string($locale) && '' !== $locale ? $locale : $this->sections->defaultLocale();

        $article = $this->storage->findBySlug($section, $locale, $slug);
        if (null === $article || ArticleState::Published !== $article->getState()) {
            return;
        }
        $this->recorder->record($article, $request);
    }
}
