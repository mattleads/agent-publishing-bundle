<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;

/**
 * @internal
 */
final class Configuration
{
    public static function build(NodeDefinition $root): void
    {
        if (!$root instanceof ArrayNodeDefinition) {
            throw new \LogicException('Root node must be an array node.');
        }

        $root
            ->children()
                ->enumNode('storage')
                    ->info('"doctrine" uses the bundled entities, "memory" keeps nothing between requests (tests), "custom" uses the services below.')
                    ->values(['doctrine', 'memory', 'custom'])
                    ->defaultValue('doctrine')
                ->end()
                ->scalarNode('article_storage')->info('Service id implementing ArticleStorageInterface (storage: custom).')->defaultNull()->end()
                ->scalarNode('api_key_storage')->info('Service id implementing ApiKeyStorageInterface (storage: custom).')->defaultNull()->end()
                ->scalarNode('stats_storage')->info('Service id implementing StatsStorageInterface (storage: custom).')->defaultNull()->end()
                ->arrayNode('locales')
                    ->info('Locales articles may be written in.')
                    ->scalarPrototype()->end()
                    ->requiresAtLeastOneElement()
                    ->defaultValue(['en'])
                ->end()
                ->scalarNode('default_locale')->defaultValue('en')->cannotBeEmpty()->end()
                ->arrayNode('sections')
                    ->info('Sections articles belong to. An agent cannot create one; declare them here.')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('path')->info('URL prefix the host app uses for this section, e.g. "/blog".')->isRequired()->cannotBeEmpty()->end()
                            ->arrayNode('locales')->info('Subset of the top-level locales. Empty means all of them.')->scalarPrototype()->end()->defaultValue([])->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('api')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('prefix')->defaultValue('/api/publisher/v1')->cannotBeEmpty()->end()
                    ->end()
                ->end()
                ->arrayNode('analytics')
                    ->info('Counts views per article and per referrer. No cookies, no IP addresses.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->scalarNode('timezone')->info('Timezone that defines a "day" in the rollups.')->defaultValue('UTC')->cannotBeEmpty()->end()
                        ->scalarNode('site_host')->info('Your site host, so links from it count as "internal". Defaults to the host of each request.')->defaultNull()->end()
                        ->scalarNode('slug_attribute')->info('Request attribute holding the article slug on routes that declare _agent_publishing_section.')->defaultValue('slug')->cannotBeEmpty()->end()
                        ->scalarNode('locale_attribute')->info('Request attribute holding the locale. Falls back to default_locale.')->defaultValue('_locale')->cannotBeEmpty()->end()
                    ->end()
                ->end()
                ->arrayNode('scheduler')
                    ->info('Register the scheduled publisher as a Symfony Scheduler task. Needs symfony/scheduler and a running messenger:consume for the schedule. Alternatively run agent-publishing:publish-scheduled from cron.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->scalarNode('frequency')->info('Interval understood by RecurringMessage::every(), e.g. "1 minute".')->defaultValue('1 minute')->cannotBeEmpty()->end()
                        ->scalarNode('schedule')->info('Name of the schedule the task is attached to.')->defaultValue('default')->cannotBeEmpty()->end()
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->ifTrue(static fn(array $c) => !\in_array($c['default_locale'], $c['locales'], true))
                ->thenInvalid('default_locale must be one of "locales".')
            ->end()
            ->validate()
                ->ifTrue(static fn(array $c) => 'custom' === $c['storage'] && (null === $c['article_storage'] || null === $c['api_key_storage'] || null === $c['stats_storage']))
                ->thenInvalid('storage "custom" requires article_storage, api_key_storage and stats_storage service ids.')
            ->end();
    }
}
