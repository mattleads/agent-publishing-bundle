<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Seo;

use Symfony\Component\HttpFoundation\Response;

final class SeoController
{
    public function __construct(
        private readonly SitemapBuilder $sitemap,
        private readonly LlmsTxtBuilder $llmsTxt,
        private readonly bool $sitemapEnabled = true,
        private readonly bool $llmsTxtEnabled = true,
        private readonly int $maxAge = 600,
    ) {}

    public function sitemap(): Response
    {
        if (!$this->sitemapEnabled) {
            return new Response('Not found', Response::HTTP_NOT_FOUND);
        }

        return new Response($this->sitemap->build(), Response::HTTP_OK, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=' . $this->maxAge]);
    }

    public function llmsTxt(): Response
    {
        if (!$this->llmsTxtEnabled) {
            return new Response('Not found', Response::HTTP_NOT_FOUND);
        }

        return new Response($this->llmsTxt->build(), Response::HTTP_OK, ['Content-Type' => 'text/markdown; charset=UTF-8', 'Cache-Control' => 'public, max-age=' . $this->maxAge]);
    }
}
