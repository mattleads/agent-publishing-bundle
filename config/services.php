<?php

declare(strict_types=1);

use MattLeads\AgentPublishingBundle\Api\ApiAccess;
use MattLeads\AgentPublishingBundle\Api\ArticleController;
use MattLeads\AgentPublishingBundle\Api\ArticleNormalizer;
use MattLeads\AgentPublishingBundle\Api\OpenApiController;
use MattLeads\AgentPublishingBundle\Api\SectionController;
use MattLeads\AgentPublishingBundle\Bridge\Doctrine\DoctrineApiKeyStorage;
use MattLeads\AgentPublishingBundle\Bridge\Doctrine\DoctrineArticleStorage;
use MattLeads\AgentPublishingBundle\Command\CreateApiKeyCommand;
use MattLeads\AgentPublishingBundle\Command\PublishScheduledCommand;
use MattLeads\AgentPublishingBundle\Contract\ApiKeyStorageInterface;
use MattLeads\AgentPublishingBundle\Contract\ArticleStorageInterface;
use MattLeads\AgentPublishingBundle\Scheduler\ScheduledPublisher;
use MattLeads\AgentPublishingBundle\Section\SectionRegistry;
use MattLeads\AgentPublishingBundle\Security\ApiKeyAuthenticator;
use MattLeads\AgentPublishingBundle\Security\ApiKeyManager;
use MattLeads\AgentPublishingBundle\Storage\InMemoryApiKeyStorage;
use MattLeads\AgentPublishingBundle\Storage\InMemoryArticleStorage;
use MattLeads\AgentPublishingBundle\Workflow\ArticleManager;
use MattLeads\AgentPublishingBundle\Workflow\ArticleWorkflow;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\abstract_arg;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire(false)
            ->autoconfigure(false);

    // --- configuration-backed ---------------------------------------------------------------------------------
    $services->set(SectionRegistry::class)
        ->args([
            '$sections' => abstract_arg('from bundle config'),
            '$locales' => abstract_arg('from bundle config'),
            '$defaultLocale' => abstract_arg('from bundle config'),
        ]);

    // --- storages (the bundle class aliases the interfaces to one of these or to host services) ---------------
    $services->set(InMemoryArticleStorage::class);
    $services->set(InMemoryApiKeyStorage::class);
    $services->set(DoctrineArticleStorage::class)->args([service('doctrine.orm.entity_manager')]);
    $services->set(DoctrineApiKeyStorage::class)->args([service('doctrine.orm.entity_manager')]);

    // --- domain -----------------------------------------------------------------------------------------------
    $services->set(ArticleWorkflow::class)
        ->args([service(ArticleStorageInterface::class), service('event_dispatcher'), service('clock')->nullOnInvalid()]);
    $services->set(ArticleManager::class)
        ->args([service(ArticleStorageInterface::class), service(SectionRegistry::class), service('clock')->nullOnInvalid()]);
    $services->set(ScheduledPublisher::class)
        ->args([service(ArticleStorageInterface::class), service(ArticleWorkflow::class), service('clock')->nullOnInvalid(), service('logger')->nullOnInvalid()]);

    // --- security ---------------------------------------------------------------------------------------------
    $services->set(ApiKeyManager::class)
        ->args([service(ApiKeyStorageInterface::class), param('kernel.secret'), service('clock')->nullOnInvalid()]);
    $services->set(ApiKeyAuthenticator::class)
        ->args([service(ApiKeyManager::class)]);

    // --- API --------------------------------------------------------------------------------------------------
    $services->set(ApiAccess::class)->args([service('security.token_storage')]);
    $services->set(ArticleNormalizer::class)->args([service(ArticleWorkflow::class)]);
    $services->set(OpenApiController::class)
        ->args([param('mattleads_agent_publishing.api.prefix')])
        ->tag('controller.service_arguments')->public();
    $services->set(SectionController::class)
        ->args([service(SectionRegistry::class), service(ApiAccess::class)])
        ->tag('controller.service_arguments')->public();
    $services->set(ArticleController::class)
        ->args([
            service(ArticleStorageInterface::class),
            service(ArticleManager::class),
            service(ArticleWorkflow::class),
            service(ArticleNormalizer::class),
            service(ApiAccess::class),
        ])
        ->tag('controller.service_arguments')->public();

    // --- console ----------------------------------------------------------------------------------------------
    $services->set(CreateApiKeyCommand::class)
        ->args([service(ApiKeyManager::class), service(SectionRegistry::class)])
        ->tag('console.command');
    $services->set(PublishScheduledCommand::class)
        ->args([service(ScheduledPublisher::class)])
        ->tag('console.command');
};
