<?php

declare(strict_types=1);

use MattLeads\AgentPublishingBundle\Api\ArticleController;
use MattLeads\AgentPublishingBundle\Api\KeyController;
use MattLeads\AgentPublishingBundle\Api\LintController;
use MattLeads\AgentPublishingBundle\Api\OpenApiController;
use MattLeads\AgentPublishingBundle\Api\SectionController;
use MattLeads\AgentPublishingBundle\Api\StatsController;
use MattLeads\AgentPublishingBundle\Mcp\McpController;
use MattLeads\AgentPublishingBundle\Seo\SeoController;
use MattLeads\AgentPublishingBundle\Workflow\ArticleWorkflow;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $prefix = '%mattleads_agent_publishing.api.prefix%';

    $routes->add('mattleads_agent_publishing_openapi', $prefix . '/openapi.json')
        ->controller([OpenApiController::class, 'document'])->methods(['GET']);

    $routes->add('mattleads_agent_publishing_sections', $prefix . '/sections')
        ->controller([SectionController::class, 'list'])->methods(['GET']);

    $routes->add('mattleads_agent_publishing_articles', $prefix . '/articles')
        ->controller([ArticleController::class, 'list'])->methods(['GET']);
    $routes->add('mattleads_agent_publishing_article_create', $prefix . '/articles')
        ->controller([ArticleController::class, 'create'])->methods(['POST']);
    $routes->add('mattleads_agent_publishing_article', $prefix . '/articles/{id}')
        ->controller([ArticleController::class, 'get'])->methods(['GET']);
    $routes->add('mattleads_agent_publishing_article_update', $prefix . '/articles/{id}')
        ->controller([ArticleController::class, 'update'])->methods(['PATCH']);
    $routes->add('mattleads_agent_publishing_article_transition', $prefix . '/articles/{id}/{transition}')
        ->controller([ArticleController::class, 'transition'])->methods(['POST'])
        ->requirements(['transition' => implode('|', ArticleWorkflow::transitions())]);

    $routes->add('mattleads_agent_publishing_article_lint', $prefix . '/articles/{id}/lint')
        ->controller([LintController::class, 'article'])->methods(['POST']);
    $routes->add('mattleads_agent_publishing_lint', $prefix . '/lint')
        ->controller([LintController::class, 'payload'])->methods(['POST']);
    $routes->add('mattleads_agent_publishing_keys', $prefix . '/keys')
        ->controller([KeyController::class, 'list'])->methods(['GET']);
    $routes->add('mattleads_agent_publishing_key_create', $prefix . '/keys')
        ->controller([KeyController::class, 'create'])->methods(['POST']);
    $routes->add('mattleads_agent_publishing_key', $prefix . '/keys/{id}')
        ->controller([KeyController::class, 'get'])->methods(['GET']);
    $routes->add('mattleads_agent_publishing_key_revoke', $prefix . '/keys/{id}')
        ->controller([KeyController::class, 'revoke'])->methods(['DELETE']);
    $routes->add('mattleads_agent_publishing_article_stats', $prefix . '/articles/{id}/stats')
        ->controller([StatsController::class, 'article'])->methods(['GET']);
    $routes->add('mattleads_agent_publishing_stats_summary', $prefix . '/stats/summary')
        ->controller([StatsController::class, 'summary'])->methods(['GET']);
    $routes->add('mattleads_agent_publishing_stats_referrers', $prefix . '/stats/referrers')
        ->controller([StatsController::class, 'referrers'])->methods(['GET']);

    $routes->add('mattleads_agent_publishing_mcp', $prefix . '%mattleads_agent_publishing.mcp.path%')
        ->controller([McpController::class, 'endpoint'])->methods(['POST', 'GET', 'DELETE']);

    // Public, outside the API prefix: exclude them from your API firewall.
    $routes->add('mattleads_agent_publishing_sitemap', '%mattleads_agent_publishing.seo.sitemap_path%')
        ->controller([SeoController::class, 'sitemap'])->methods(['GET']);
    $routes->add('mattleads_agent_publishing_llms_txt', '%mattleads_agent_publishing.seo.llms_txt_path%')
        ->controller([SeoController::class, 'llmsTxt'])->methods(['GET']);
};
