<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Seo;

use MattLeads\AgentPublishingBundle\Model\ArticleInterface;
use MattLeads\AgentPublishingBundle\Section\SectionRegistry;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Placeholders: {base}, {section}, {section_path}, {locale}, {slug}. The base URL comes from configuration or, when
 * unset, from the current request.
 */
final class PatternUrlResolver implements UrlResolverInterface
{
    public const DEFAULT_PATTERN = '{base}{section_path}/{slug}';

    public function __construct(
        private readonly SectionRegistry $sections,
        private readonly string $pattern = self::DEFAULT_PATTERN,
        private readonly ?string $baseUrl = null,
        private readonly ?RequestStack $requests = null,
    ) {}

    public function urlFor(ArticleInterface $article): string
    {
        $section = $this->sections->get($article->getSection());

        return strtr($this->pattern, [
            '{base}' => $this->baseUrl(),
            '{section}' => $article->getSection(),
            '{section_path}' => null !== $section ? rtrim($section->path, '/') : '/' . $article->getSection(),
            '{locale}' => $article->getLocale(),
            '{slug}' => $article->getSlug(),
        ]);
    }

    public function baseUrl(): string
    {
        if (null !== $this->baseUrl) {
            return rtrim($this->baseUrl, '/');
        }
        $request = $this->requests?->getCurrentRequest();

        return null !== $request ? $request->getSchemeAndHttpHost() : '';
    }
}
