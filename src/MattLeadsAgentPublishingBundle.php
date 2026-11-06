<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle;

use MattLeads\AgentPublishingBundle\Analytics\ArticleViewedHandler;
use MattLeads\AgentPublishingBundle\Analytics\ArticleViewListener;
use MattLeads\AgentPublishingBundle\Analytics\ViewRecorder;
use MattLeads\AgentPublishingBundle\Analytics\VisitClassifier;
use MattLeads\AgentPublishingBundle\Api\KeyController;
use MattLeads\AgentPublishingBundle\Api\OpenApiController;
use MattLeads\AgentPublishingBundle\Api\StatsController;
use MattLeads\AgentPublishingBundle\Bridge\Doctrine\DoctrineApiKeyStorage;
use MattLeads\AgentPublishingBundle\Bridge\Doctrine\DoctrineArticleStorage;
use MattLeads\AgentPublishingBundle\Bridge\Doctrine\DoctrineStatsStorage;
use MattLeads\AgentPublishingBundle\Contract\ApiKeyStorageInterface;
use MattLeads\AgentPublishingBundle\Contract\ArticleStorageInterface;
use MattLeads\AgentPublishingBundle\Contract\StatsStorageInterface;
use MattLeads\AgentPublishingBundle\DependencyInjection\Configuration;
use MattLeads\AgentPublishingBundle\Lint\Rule\LinksRule;
use MattLeads\AgentPublishingBundle\Scheduler\ScheduledPublisher;
use MattLeads\AgentPublishingBundle\Seo\JsonLdBuilder;
use MattLeads\AgentPublishingBundle\Seo\LlmsTxtBuilder;
use MattLeads\AgentPublishingBundle\Seo\PatternUrlResolver;
use MattLeads\AgentPublishingBundle\Seo\SeoController;
use MattLeads\AgentPublishingBundle\Seo\UrlResolverInterface;
use MattLeads\AgentPublishingBundle\Twig\JsonLdExtension;
use MattLeads\AgentPublishingBundle\Section\SectionRegistry;
use MattLeads\AgentPublishingBundle\Storage\InMemoryApiKeyStorage;
use MattLeads\AgentPublishingBundle\Storage\InMemoryArticleStorage;
use MattLeads\AgentPublishingBundle\Storage\InMemoryStatsStorage;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Scheduler\RecurringMessage;
use Twig\Extension\AbstractExtension;

final class MattLeadsAgentPublishingBundle extends AbstractBundle
{
    protected string $extensionAlias = 'mattleads_agent_publishing';

    public function configure(DefinitionConfigurator $definition): void
    {
        Configuration::build($definition->rootNode());
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Map the bundled models whenever Doctrine ORM is around. The mapping is inert when another storage is used.
        if ($builder->hasExtension('doctrine')) {
            $container->extension('doctrine', [
                'orm' => [
                    'mappings' => [
                        'MattLeadsAgentPublishingBundle' => [
                            'type' => 'attribute',
                            'dir' => __DIR__ . '/Model',
                            'prefix' => 'MattLeads\\AgentPublishingBundle\\Model',
                            'is_bundle' => false,
                        ],
                    ],
                ],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');

        /* @var array{storage: string, article_storage: ?string, api_key_storage: ?string, stats_storage: ?string, locales: list<string>, default_locale: string, sections: array<string, array{path: string, locales: list<string>, lint: array{require_faq: bool, min_words: int, min_internal_links: int}}>, api: array{prefix: string, key_management: bool}, analytics: array{enabled: bool, timezone: string, site_host: ?string, slug_attribute: string, locale_attribute: string}, scheduler: array{enabled: bool, frequency: string, schedule: string}, seo: array{base_url: ?string, url_pattern: string, url_resolver: ?string, site_name: ?string, site_description: ?string, publisher: array{name: ?string, url: ?string, logo: ?string}, author: array{name: ?string, url: ?string}, jsonld_type: string, sitemap: array{enabled: bool, path: string}, llms_txt: array{enabled: bool, path: string, per_section: int}, max_age: int}} $config */
        $builder->setParameter('mattleads_agent_publishing.api.prefix', rtrim($config['api']['prefix'], '/'));
        $builder->getDefinition(KeyController::class)->setArgument('$enabled', $config['api']['key_management']);
        $builder->getDefinition(OpenApiController::class)->setArgument('$keyManagement', $config['api']['key_management']);

        $builder->getDefinition(SectionRegistry::class)
            ->setArgument('$sections', $config['sections'])
            ->setArgument('$locales', $config['locales'])
            ->setArgument('$defaultLocale', $config['default_locale']);

        $builder->getDefinition(LinksRule::class)->setArgument('$siteHost', $config['analytics']['site_host']);

        // --- seo --------------------------------------------------------------------------------------------------
        $seo = $config['seo'];
        $builder->setParameter('mattleads_agent_publishing.seo.sitemap_path', $seo['sitemap']['path']);
        $builder->setParameter('mattleads_agent_publishing.seo.llms_txt_path', $seo['llms_txt']['path']);
        if (null !== $seo['url_resolver']) {
            $builder->setAlias(UrlResolverInterface::class, $seo['url_resolver']);
            $builder->removeDefinition(PatternUrlResolver::class);
        } else {
            $builder->setAlias(UrlResolverInterface::class, PatternUrlResolver::class);
            $builder->getDefinition(PatternUrlResolver::class)
                ->setArgument('$pattern', $seo['url_pattern'])
                ->setArgument('$baseUrl', $seo['base_url']);
        }
        $builder->getDefinition(JsonLdBuilder::class)
            ->setArgument('$type', $seo['jsonld_type'])
            ->setArgument('$publisher', $seo['publisher'])
            ->setArgument('$author', $seo['author']);
        $builder->getDefinition(LlmsTxtBuilder::class)
            ->setArgument('$siteName', $seo['site_name'])
            ->setArgument('$siteDescription', $seo['site_description'])
            ->setArgument('$perSection', $seo['llms_txt']['per_section']);
        $builder->getDefinition(SeoController::class)
            ->setArgument('$sitemapEnabled', $seo['sitemap']['enabled'])
            ->setArgument('$llmsTxtEnabled', $seo['llms_txt']['enabled'])
            ->setArgument('$maxAge', $seo['max_age']);
        if (class_exists(AbstractExtension::class)) {
            $builder->getDefinition(JsonLdExtension::class)->addTag('twig.extension');
        } else {
            $builder->removeDefinition(JsonLdExtension::class);
        }

        // --- analytics --------------------------------------------------------------------------------------------
        $builder->getDefinition('mattleads_agent_publishing.analytics.timezone')->setArgument(0, $config['analytics']['timezone']);
        if ($config['analytics']['enabled']) {
            $builder->getDefinition(VisitClassifier::class)->setArgument('$siteHost', $config['analytics']['site_host']);
            $builder->getDefinition(ArticleViewListener::class)
                ->setArgument('$slugAttribute', $config['analytics']['slug_attribute'])
                ->setArgument('$localeAttribute', $config['analytics']['locale_attribute'])
                ->addTag('kernel.event_listener', ['event' => 'kernel.terminate', 'method' => 'onTerminate']);
            if (interface_exists(MessageBusInterface::class)) {
                $builder->getDefinition(ArticleViewedHandler::class)->addTag('messenger.message_handler');
            }
        } else {
            foreach ([VisitClassifier::class, ArticleViewedHandler::class, ViewRecorder::class, ArticleViewListener::class, StatsController::class] as $id) {
                $builder->removeDefinition($id);
            }
        }

        if ($config['scheduler']['enabled']) {
            if (!class_exists(RecurringMessage::class)) {
                throw new \InvalidArgumentException('mattleads_agent_publishing.scheduler.enabled needs symfony/scheduler. Install it or run agent-publishing:publish-scheduled from cron.');
            }
            $builder->getDefinition(ScheduledPublisher::class)->addTag('scheduler.task', [
                'trigger' => 'every',
                'frequency' => $config['scheduler']['frequency'],
                'schedule' => $config['scheduler']['schedule'],
                'method' => '__invoke',
            ]);
        }

        switch ($config['storage']) {
            case 'doctrine':
                /** @var array<string, class-string> $bundles */
                $bundles = $builder->hasParameter('kernel.bundles') ? $builder->getParameter('kernel.bundles') : [];
                if (!isset($bundles['DoctrineBundle'])) {
                    throw new \InvalidArgumentException('mattleads_agent_publishing.storage "doctrine" needs doctrine/doctrine-bundle. Use "memory" or "custom" otherwise.');
                }
                $builder->setAlias(ArticleStorageInterface::class, DoctrineArticleStorage::class);
                $builder->setAlias(ApiKeyStorageInterface::class, DoctrineApiKeyStorage::class);
                $builder->setAlias(StatsStorageInterface::class, DoctrineStatsStorage::class);
                $builder->removeDefinition(InMemoryArticleStorage::class);
                $builder->removeDefinition(InMemoryApiKeyStorage::class);
                $builder->removeDefinition(InMemoryStatsStorage::class);
                break;
            case 'memory':
                $builder->setAlias(ArticleStorageInterface::class, InMemoryArticleStorage::class);
                $builder->setAlias(ApiKeyStorageInterface::class, InMemoryApiKeyStorage::class);
                $builder->setAlias(StatsStorageInterface::class, InMemoryStatsStorage::class);
                $builder->removeDefinition(DoctrineArticleStorage::class);
                $builder->removeDefinition(DoctrineApiKeyStorage::class);
                $builder->removeDefinition(DoctrineStatsStorage::class);
                break;
            default:
                $builder->setAlias(ArticleStorageInterface::class, (string) $config['article_storage']);
                $builder->setAlias(ApiKeyStorageInterface::class, (string) $config['api_key_storage']);
                $builder->setAlias(StatsStorageInterface::class, (string) $config['stats_storage']);
                foreach ([DoctrineArticleStorage::class, DoctrineApiKeyStorage::class, DoctrineStatsStorage::class, InMemoryArticleStorage::class, InMemoryApiKeyStorage::class, InMemoryStatsStorage::class] as $id) {
                    $builder->removeDefinition($id);
                }
        }
    }
}
