<?php

declare(strict_types=1);

use MattLeads\AgentPublishingBundle\Api\ArticleController;
use MattLeads\AgentPublishingBundle\Api\SectionController;
use MattLeads\AgentPublishingBundle\Workflow\ArticleWorkflow;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $prefix = '%mattleads_agent_publishing.api.prefix%';

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
};
