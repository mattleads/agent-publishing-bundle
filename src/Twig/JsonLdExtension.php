<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Twig;

use MattLeads\AgentPublishingBundle\Model\ArticleInterface;
use MattLeads\AgentPublishingBundle\Seo\JsonLdBuilder;
use MattLeads\AgentPublishingBundle\Seo\UrlResolverInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class JsonLdExtension extends AbstractExtension
{
    public function __construct(private readonly JsonLdBuilder $jsonLd, private readonly UrlResolverInterface $urls) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('agent_publishing_jsonld', $this->jsonLd->script(...), ['is_safe' => ['html']]),
            new TwigFunction('agent_publishing_url', fn(ArticleInterface $a) => $this->urls->urlFor($a)),
        ];
    }
}
