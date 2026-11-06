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

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /** @param array<string, mixed> $bundleConfig */
    public function __construct(private readonly array $bundleConfig = [], private readonly bool $refusePublishing = false)
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
                ['path' => '^/api/publisher/v1/openapi\.json$', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/api/', 'roles' => 'ROLE_AGENT_PUBLISHING'],
            ],
        ]);
        $container->services()->set(BlogController::class)->tag('controller.service_arguments')->public();
        if ($this->refusePublishing) {
            $container->services()->set(RefusingArticleStorage::class)
                ->decorate(\MattLeads\AgentPublishingBundle\Contract\ArticleStorageInterface::class)
                ->args([service('.inner')]);
        }
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
        $routes->add('blog_show', '/blog/{slug}')
            ->controller([BlogController::class, 'show'])
            ->defaults(['_agent_publishing_section' => 'blog', '_locale' => 'en'])
            ->methods(['GET']);
    }

    public function getProjectDir(): string
    {
        return self::baseDir() . '/project';
    }

    public function getCacheDir(): string
    {
        return self::baseDir() . '/' . md5(serialize([$this->bundleConfig, $this->refusePublishing])) . '/cache';
    }

    public function getLogDir(): string
    {
        return self::baseDir() . '/log';
    }

    /** Per checkout, so two clones never share a compiled container that points at the other's sources. */
    private static function baseDir(): string
    {
        return sys_get_temp_dir() . '/mattleads_agent_publishing/' . md5(__DIR__);
    }
}
