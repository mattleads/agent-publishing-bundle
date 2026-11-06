<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle;

use MattLeads\AgentPublishingBundle\Bridge\Doctrine\DoctrineApiKeyStorage;
use MattLeads\AgentPublishingBundle\Bridge\Doctrine\DoctrineArticleStorage;
use MattLeads\AgentPublishingBundle\Contract\ApiKeyStorageInterface;
use MattLeads\AgentPublishingBundle\Contract\ArticleStorageInterface;
use MattLeads\AgentPublishingBundle\DependencyInjection\Configuration;
use MattLeads\AgentPublishingBundle\Scheduler\ScheduledPublisher;
use MattLeads\AgentPublishingBundle\Section\SectionRegistry;
use MattLeads\AgentPublishingBundle\Storage\InMemoryApiKeyStorage;
use MattLeads\AgentPublishingBundle\Storage\InMemoryArticleStorage;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Scheduler\RecurringMessage;

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

        /* @var array{storage: string, article_storage: ?string, api_key_storage: ?string, locales: list<string>, default_locale: string, sections: array<string, array{path: string, locales: list<string>}>, api: array{prefix: string}, scheduler: array{enabled: bool, frequency: string, schedule: string}} $config */
        $builder->setParameter('mattleads_agent_publishing.api.prefix', rtrim($config['api']['prefix'], '/'));

        $builder->getDefinition(SectionRegistry::class)
            ->setArgument('$sections', $config['sections'])
            ->setArgument('$locales', $config['locales'])
            ->setArgument('$defaultLocale', $config['default_locale']);

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
                $builder->removeDefinition(InMemoryArticleStorage::class);
                $builder->removeDefinition(InMemoryApiKeyStorage::class);
                break;
            case 'memory':
                $builder->setAlias(ArticleStorageInterface::class, InMemoryArticleStorage::class);
                $builder->setAlias(ApiKeyStorageInterface::class, InMemoryApiKeyStorage::class);
                $builder->removeDefinition(DoctrineArticleStorage::class);
                $builder->removeDefinition(DoctrineApiKeyStorage::class);
                break;
            default:
                $builder->setAlias(ArticleStorageInterface::class, (string) $config['article_storage']);
                $builder->setAlias(ApiKeyStorageInterface::class, (string) $config['api_key_storage']);
                foreach ([DoctrineArticleStorage::class, DoctrineApiKeyStorage::class, InMemoryArticleStorage::class, InMemoryApiKeyStorage::class] as $id) {
                    $builder->removeDefinition($id);
                }
        }
    }
}
