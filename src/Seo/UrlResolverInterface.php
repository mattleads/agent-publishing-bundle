<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Seo;

use MattLeads\AgentPublishingBundle\Model\ArticleInterface;

/**
 * Turns an article into its public, absolute URL. The bundled PatternUrlResolver covers "{base}{section_path}/{slug}"
 * style sites; implement this and set seo.url_resolver when your routing differs.
 */
interface UrlResolverInterface
{
    public function urlFor(ArticleInterface $article): string;
}
