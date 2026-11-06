<?php

declare(strict_types=1);

use MattLeads\AgentPublishingBundle\Analytics\ArticleViewedHandler;
use MattLeads\AgentPublishingBundle\Analytics\ArticleViewListener;
use MattLeads\AgentPublishingBundle\Analytics\ViewRecorder;
use MattLeads\AgentPublishingBundle\Analytics\VisitClassifier;
use MattLeads\AgentPublishingBundle\Api\ApiAccess;
use MattLeads\AgentPublishingBundle\Api\ApiKeyNormalizer;
use MattLeads\AgentPublishingBundle\Api\ArticleController;
use MattLeads\AgentPublishingBundle\Api\ArticleNormalizer;
use MattLeads\AgentPublishingBundle\Api\AuthorController;
use MattLeads\AgentPublishingBundle\Author\AuthorRegistry;
use MattLeads\AgentPublishingBundle\Api\KeyController;
use MattLeads\AgentPublishingBundle\Api\LintController;
use MattLeads\AgentPublishingBundle\Api\OpenApiController;
use MattLeads\AgentPublishingBundle\Api\SectionController;
use MattLeads\AgentPublishingBundle\Api\StatsController;
use MattLeads\AgentPublishingBundle\Bridge\Doctrine\DoctrineApiKeyStorage;
use MattLeads\AgentPublishingBundle\Bridge\Doctrine\DoctrineArticleStorage;
use MattLeads\AgentPublishingBundle\Bridge\Doctrine\DoctrineStatsStorage;
use MattLeads\AgentPublishingBundle\Command\CreateApiKeyCommand;
use MattLeads\AgentPublishingBundle\Command\ListApiKeysCommand;
use MattLeads\AgentPublishingBundle\Command\PublishScheduledCommand;
use MattLeads\AgentPublishingBundle\Command\RevokeApiKeyCommand;
use MattLeads\AgentPublishingBundle\Contract\ApiKeyStorageInterface;
use MattLeads\AgentPublishingBundle\Contract\ArticleStorageInterface;
use MattLeads\AgentPublishingBundle\Contract\StatsStorageInterface;
use MattLeads\AgentPublishingBundle\Lint\Content\ContentInspector;
use MattLeads\AgentPublishingBundle\Lint\Linter;
use MattLeads\AgentPublishingBundle\Lint\Rule\FaqRule;
use MattLeads\AgentPublishingBundle\Lint\Rule\HeadingsRule;
use MattLeads\AgentPublishingBundle\Lint\Rule\ImagesAltRule;
use MattLeads\AgentPublishingBundle\Lint\Rule\LinksRule;
use MattLeads\AgentPublishingBundle\Lint\Rule\MetaDescriptionRule;
use MattLeads\AgentPublishingBundle\Lint\Rule\OpeningParagraphRule;
use MattLeads\AgentPublishingBundle\Lint\Rule\ReadabilityRule;
use MattLeads\AgentPublishingBundle\Lint\Rule\SlugRule;
use MattLeads\AgentPublishingBundle\Lint\Rule\SummaryRule;
use MattLeads\AgentPublishingBundle\Lint\Rule\TagsRule;
use MattLeads\AgentPublishingBundle\Lint\Rule\TitleLengthRule;
use MattLeads\AgentPublishingBundle\Lint\Rule\WordCountRule;
use MattLeads\AgentPublishingBundle\Mcp\McpController;
use MattLeads\AgentPublishingBundle\Mcp\McpServer;
use MattLeads\AgentPublishingBundle\Mcp\Tool\CreateDraftTool;
use MattLeads\AgentPublishingBundle\Mcp\Tool\GetArticleTool;
use MattLeads\AgentPublishingBundle\Mcp\Tool\LintTool;
use MattLeads\AgentPublishingBundle\Mcp\Tool\ListAuthorsTool;
use MattLeads\AgentPublishingBundle\Mcp\Tool\ListArticlesTool;
use MattLeads\AgentPublishingBundle\Mcp\Tool\ListSectionsTool;
use MattLeads\AgentPublishingBundle\Mcp\Tool\StatsTool;
use MattLeads\AgentPublishingBundle\Mcp\Tool\StyleGuideTool;
use MattLeads\AgentPublishingBundle\Mcp\Tool\TransitionTool;
use MattLeads\AgentPublishingBundle\Mcp\Tool\UpdateDraftTool;
use MattLeads\AgentPublishingBundle\Scheduler\ScheduledPublisher;
use MattLeads\AgentPublishingBundle\Section\SectionRegistry;
use MattLeads\AgentPublishingBundle\Seo\JsonLdBuilder;
use MattLeads\AgentPublishingBundle\Seo\LlmsTxtBuilder;
use MattLeads\AgentPublishingBundle\Seo\PatternUrlResolver;
use MattLeads\AgentPublishingBundle\Seo\PublishedIndex;
use MattLeads\AgentPublishingBundle\Seo\SeoController;
use MattLeads\AgentPublishingBundle\Seo\SitemapBuilder;
use MattLeads\AgentPublishingBundle\Seo\UrlResolverInterface;
use MattLeads\AgentPublishingBundle\Twig\JsonLdExtension;
use MattLeads\AgentPublishingBundle\Security\ApiKeyAuthenticator;
use MattLeads\AgentPublishingBundle\Security\ApiKeyManager;
use MattLeads\AgentPublishingBundle\Storage\InMemoryApiKeyStorage;
use MattLeads\AgentPublishingBundle\Storage\InMemoryArticleStorage;
use MattLeads\AgentPublishingBundle\Storage\InMemoryStatsStorage;
use MattLeads\AgentPublishingBundle\Workflow\ArticleManager;
use MattLeads\AgentPublishingBundle\Workflow\ArticleWorkflow;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\abstract_arg;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

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
    $services->set(AuthorRegistry::class)
        ->args([
            '$authors' => abstract_arg('from bundle config'),
            '$defaultAuthor' => abstract_arg('from bundle config'),
        ]);

    // --- storages (the bundle class aliases the interfaces to one of these or to host services) ---------------
    $services->set(InMemoryArticleStorage::class);
    $services->set(InMemoryApiKeyStorage::class);
    $services->set(DoctrineArticleStorage::class)->args([service('doctrine.orm.entity_manager')]);
    $services->set(DoctrineApiKeyStorage::class)->args([service('doctrine.orm.entity_manager')]);
    $services->set(InMemoryStatsStorage::class);
    $services->set(DoctrineStatsStorage::class)->args([service('doctrine.orm.entity_manager')]);

    // --- domain -----------------------------------------------------------------------------------------------
    $services->set(ArticleWorkflow::class)
        ->args([service(ArticleStorageInterface::class), service('event_dispatcher'), service('clock')->nullOnInvalid()]);
    $services->set(ArticleManager::class)
        ->args([
            service(ArticleStorageInterface::class),
            service(SectionRegistry::class),
            service('clock')->nullOnInvalid(),
            service(AuthorRegistry::class),
            '$editPublished' => abstract_arg('from bundle config'),
        ]);
    $services->set(ScheduledPublisher::class)
        ->args([service(ArticleStorageInterface::class), service(ArticleWorkflow::class), service('clock')->nullOnInvalid(), service('logger')->nullOnInvalid()]);

    // --- analytics --------------------------------------------------------------------------------------------
    $services->set('mattleads_agent_publishing.analytics.timezone', DateTimeZone::class)
        ->args([abstract_arg('from bundle config')]);
    $services->set(VisitClassifier::class)
        ->args(['$siteHost' => abstract_arg('from bundle config')]);
    $services->set(ArticleViewedHandler::class)
        ->args([service(StatsStorageInterface::class), service('mattleads_agent_publishing.analytics.timezone')]);
    $services->set(ViewRecorder::class)
        ->args([service(VisitClassifier::class), service(ArticleViewedHandler::class), service('messenger.default_bus')->nullOnInvalid(), service('clock')->nullOnInvalid()])
        ->public();
    $services->set(ArticleViewListener::class)
        ->args([
            service(ArticleStorageInterface::class),
            service(SectionRegistry::class),
            service(ViewRecorder::class),
            '$slugAttribute' => abstract_arg('from bundle config'),
            '$localeAttribute' => abstract_arg('from bundle config'),
        ]);

    // --- lint -------------------------------------------------------------------------------------------------
    $services->set(ContentInspector::class);
    $priority = 100;
    foreach ([TitleLengthRule::class, SummaryRule::class, MetaDescriptionRule::class, OpeningParagraphRule::class, HeadingsRule::class, WordCountRule::class, ReadabilityRule::class, ImagesAltRule::class, TagsRule::class, FaqRule::class] as $rule) {
        $services->set($rule)->tag('mattleads_agent_publishing.lint_rule', ['priority' => $priority--]);
    }
    $services->set(LinksRule::class)
        ->args(['$siteHost' => abstract_arg('from bundle config')])
        ->tag('mattleads_agent_publishing.lint_rule', ['priority' => 50]);
    $services->set(SlugRule::class)
        ->args([service(ArticleStorageInterface::class)])
        ->tag('mattleads_agent_publishing.lint_rule', ['priority' => 40]);
    $services->set(Linter::class)
        ->args([tagged_iterator('mattleads_agent_publishing.lint_rule')]);

    // --- seo --------------------------------------------------------------------------------------------------
    $services->set(PatternUrlResolver::class)
        ->args([
            service(SectionRegistry::class),
            '$pattern' => abstract_arg('from bundle config'),
            '$baseUrl' => abstract_arg('from bundle config'),
            '$requests' => service('request_stack')->nullOnInvalid(),
        ]);
    $services->set(PublishedIndex::class)->args([service(ArticleStorageInterface::class)]);
    $services->set(JsonLdBuilder::class)
        ->args([
            service(UrlResolverInterface::class),
            '$type' => abstract_arg('from bundle config'),
            '$publisher' => abstract_arg('from bundle config'),
            '$author' => abstract_arg('from bundle config'),
            '$authors' => service(AuthorRegistry::class),
        ])
        ->public();
    $services->set(SitemapBuilder::class)
        ->args([service(PublishedIndex::class), service(UrlResolverInterface::class), service(SectionRegistry::class)]);
    $services->set(LlmsTxtBuilder::class)
        ->args([
            service(PublishedIndex::class),
            service(UrlResolverInterface::class),
            service(SectionRegistry::class),
            '$siteName' => abstract_arg('from bundle config'),
            '$siteDescription' => abstract_arg('from bundle config'),
            '$perSection' => abstract_arg('from bundle config'),
        ]);
    $services->set(SeoController::class)
        ->args([
            service(SitemapBuilder::class),
            service(LlmsTxtBuilder::class),
            '$sitemapEnabled' => abstract_arg('from bundle config'),
            '$llmsTxtEnabled' => abstract_arg('from bundle config'),
            '$maxAge' => abstract_arg('from bundle config'),
        ])
        ->tag('controller.service_arguments')->public();
    $services->set(JsonLdExtension::class)
        ->args([service(JsonLdBuilder::class), service(UrlResolverInterface::class), service(AuthorRegistry::class)]);

    // --- mcp --------------------------------------------------------------------------------------------------
    $tag = 'mattleads_agent_publishing.mcp_tool';
    $services->set(ListSectionsTool::class)->args([service(SectionRegistry::class)])->tag($tag, ['priority' => 100]);
    $services->set(StyleGuideTool::class)->args(['$styleGuide' => abstract_arg('from bundle config')])->tag($tag, ['priority' => 95]);
    $services->set(ListAuthorsTool::class)->args([service(AuthorRegistry::class)])->tag($tag, ['priority' => 92]);
    $services->set(ListArticlesTool::class)->args([service(ArticleStorageInterface::class), service(ArticleNormalizer::class)])->tag($tag, ['priority' => 90]);
    $services->set(GetArticleTool::class)->args([service(ArticleStorageInterface::class), service(ArticleNormalizer::class)])->tag($tag, ['priority' => 85]);
    $services->set('mattleads_agent_publishing.mcp.lint_draft', LintTool::class)
        ->args([false, service(Linter::class), service(ContentInspector::class), service(ArticleStorageInterface::class), service(SectionRegistry::class)])->tag($tag, ['priority' => 80]);
    $services->set('mattleads_agent_publishing.mcp.lint_article', LintTool::class)
        ->args([true, service(Linter::class), service(ContentInspector::class), service(ArticleStorageInterface::class), service(SectionRegistry::class)])->tag($tag, ['priority' => 75]);
    $services->set(CreateDraftTool::class)->args([service(ArticleManager::class), service(ArticleNormalizer::class)])->tag($tag, ['priority' => 70]);
    $services->set(UpdateDraftTool::class)->args([service(ArticleStorageInterface::class), service(ArticleManager::class), service(ArticleNormalizer::class)])->tag($tag, ['priority' => 65]);
    $priority = 60;
    foreach (['submit', 'approve', 'reject', 'publish', 'archive'] as $transition) {
        $services->set('mattleads_agent_publishing.mcp.' . $transition, TransitionTool::class)
            ->args([$transition, service(ArticleStorageInterface::class), service(ArticleWorkflow::class), service(ArticleNormalizer::class)])
            ->tag($tag, ['priority' => $priority--]);
    }
    foreach (StatsTool::KINDS as $kind) {
        $services->set('mattleads_agent_publishing.mcp.stats_' . $kind, StatsTool::class)
            ->args([$kind, service(StatsStorageInterface::class), service(ArticleStorageInterface::class), service(SectionRegistry::class), service('mattleads_agent_publishing.analytics.timezone'), service('clock')->nullOnInvalid()])
            ->tag($tag, ['priority' => $priority--]);
    }
    $services->set(McpServer::class)
        ->args([tagged_iterator($tag), '$instructions' => abstract_arg('from bundle config')]);
    $services->set(McpController::class)
        ->args([service(McpServer::class), service(ApiAccess::class), '$enabled' => abstract_arg('from bundle config')])
        ->tag('controller.service_arguments')->public();

    // --- security ---------------------------------------------------------------------------------------------
    $services->set(ApiKeyManager::class)
        ->args([service(ApiKeyStorageInterface::class), param('kernel.secret'), service('clock')->nullOnInvalid()]);
    $services->set(ApiKeyAuthenticator::class)
        ->args([service(ApiKeyManager::class)]);

    // --- API --------------------------------------------------------------------------------------------------
    $services->set(ApiAccess::class)->args([service('security.token_storage')]);
    $services->set(ArticleNormalizer::class)->args([service(ArticleWorkflow::class), service(ArticleStorageInterface::class)]);
    $services->set(AuthorController::class)
        ->args([service(AuthorRegistry::class), service(ApiAccess::class)])
        ->tag('controller.service_arguments')->public();
    $services->set(OpenApiController::class)
        ->args([param('mattleads_agent_publishing.api.prefix'), '$keyManagement' => abstract_arg('from bundle config')])
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

    $services->set(ApiKeyNormalizer::class);
    $services->set(KeyController::class)
        ->args([
            service(ApiKeyManager::class),
            service(ApiKeyStorageInterface::class),
            service(ApiKeyNormalizer::class),
            service(SectionRegistry::class),
            service(ApiAccess::class),
            service('clock')->nullOnInvalid(),
            '$enabled' => abstract_arg('from bundle config'),
        ])
        ->tag('controller.service_arguments')->public();
    $services->set(LintController::class)
        ->args([
            service(Linter::class),
            service(ContentInspector::class),
            service(ArticleStorageInterface::class),
            service(SectionRegistry::class),
            service(ApiAccess::class),
        ])
        ->tag('controller.service_arguments')->public();
    $services->set(StatsController::class)
        ->args([
            service(StatsStorageInterface::class),
            service(ArticleStorageInterface::class),
            service(SectionRegistry::class),
            service(ApiAccess::class),
            service('mattleads_agent_publishing.analytics.timezone'),
            service('clock')->nullOnInvalid(),
        ])
        ->tag('controller.service_arguments')->public();

    // --- console ----------------------------------------------------------------------------------------------
    $services->set(CreateApiKeyCommand::class)
        ->args([service(ApiKeyManager::class), service(SectionRegistry::class)])
        ->tag('console.command');
    $services->set(ListApiKeysCommand::class)
        ->args([service(ApiKeyStorageInterface::class)])
        ->tag('console.command');
    $services->set(RevokeApiKeyCommand::class)
        ->args([service(ApiKeyManager::class), service(ApiKeyStorageInterface::class)])
        ->tag('console.command');
    $services->set(PublishScheduledCommand::class)
        ->args([service(ScheduledPublisher::class)])
        ->tag('console.command');
};
