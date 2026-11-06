<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Tests\Functional;

use MattLeads\AgentPublishingBundle\Api\OpenApiDocument;
use MattLeads\AgentPublishingBundle\Model\Scope;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\RouterInterface;

final class OpenApiTest extends TestCase
{
    public function testDocumentIsPublicAndCoversEveryRoute(): void
    {
        $kernel = new TestKernel(['storage' => 'memory']);
        $kernel->boot();
        $client = new KernelBrowser($kernel);
        $client->disableReboot();

        $client->request('GET', '/api/publisher/v1/openapi.json');
        self::assertSame(200, $client->getResponse()->getStatusCode(), 'the document is readable without a key');
        /** @var array<string, mixed> $doc */
        $doc = json_decode((string) $client->getResponse()->getContent(), true, 64, \JSON_THROW_ON_ERROR);
        self::assertSame('3.1.0', $doc['openapi']);
        self::assertSame(OpenApiDocument::VERSION, $doc['info']['version']);

        // Every API route of the bundle, except the document itself, is described with its HTTP method.
        $container = $kernel->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);
        $router = $container->get(RouterInterface::class);
        \assert($router instanceof RouterInterface);
        $described = [];
        foreach ($doc['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $described[] = strtoupper((string) $method) . ' ' . $path;
            }
        }
        sort($described);

        $expected = [];
        foreach ($router->getRouteCollection() as $name => $route) {
            if (!str_starts_with($name, 'mattleads_agent_publishing_') || \in_array($name, ['mattleads_agent_publishing_openapi', 'mattleads_agent_publishing_sitemap', 'mattleads_agent_publishing_llms_txt'], true)) {
                continue;
            }
            foreach ($route->getMethods() as $method) {
                if ('mattleads_agent_publishing_article_transition' === $name) {
                    foreach (explode('|', (string) $route->getRequirement('transition')) as $transition) {
                        $expected[] = $method . ' ' . str_replace('{transition}', $transition, $route->getPath());
                    }
                } else {
                    $expected[] = $method . ' ' . $route->getPath();
                }
            }
        }
        sort($expected);
        self::assertSame($expected, $described);

        // Every operation declares a known scope and the bearer security scheme is global.
        foreach ($doc['paths'] as $operations) {
            foreach ($operations as $operation) {
                self::assertNotNull(Scope::tryFrom($operation['x-scope']), $operation['operationId']);
            }
        }
        self::assertSame([['apiKey' => []]], $doc['security']);
        self::assertSame('bearer', $doc['components']['securitySchemes']['apiKey']['scheme']);

        $kernel->shutdown();
    }
}
