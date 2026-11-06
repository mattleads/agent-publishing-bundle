<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Tests\Functional;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use MattLeads\AgentPublishingBundle\MattLeadsAgentPublishingBundle;
use MattLeads\AgentPublishingBundle\Security\ApiKeyAuthenticator;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /** @param array<string, mixed> $bundleConfig */
    public function __construct(private readonly array $bundleConfig = [])
    {
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        if ('doctrine' === ($this->bundleConfig['storage'] ?? 'memory')) {
            yield new DoctrineBundle();
        }
        yield new MattLeadsAgentPublishingBundle();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test-secret',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'router' => ['utf8' => true],
        ]);
        if ('doctrine' === ($this->bundleConfig['storage'] ?? 'memory')) {
            $container->extension('doctrine', [
                'dbal' => ['url' => 'sqlite:///:memory:'],
                'orm' => [
                    'auto_mapping' => false,
                    'controller_resolver' => ['auto_mapping' => false],
                ],
            ]);
        }
        $container->extension('security', [
            'providers' => ['none' => ['memory' => []]],
            'firewalls' => [
                'api' => [
                    'pattern' => '^/api/',
                    'stateless' => true,
                    'provider' => 'none',
                    'custom_authenticators' => [ApiKeyAuthenticator::class],
                    'entry_point' => ApiKeyAuthenticator::class,
                ],
            ],
            'access_control' => [
                ['path' => '^/api/', 'roles' => 'ROLE_AGENT_PUBLISHING'],
            ],
        ]);
        $container->extension('mattleads_agent_publishing', $this->bundleConfig + [
            'storage' => 'memory',
            'locales' => ['en', 'de'],
            'default_locale' => 'en',
            'sections' => [
                'blog' => ['path' => '/blog'],
                'docs' => ['path' => '/docs', 'locales' => ['en']],
            ],
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(\dirname(__DIR__, 2) . '/config/routes.php');
    }

    public function getProjectDir(): string
    {
        return sys_get_temp_dir() . '/mattleads_agent_publishing/project';
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/mattleads_agent_publishing/' . md5(serialize($this->bundleConfig)) . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/mattleads_agent_publishing/log';
    }
}
