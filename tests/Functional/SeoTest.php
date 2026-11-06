<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Tests\Functional;

use MattLeads\AgentPublishingBundle\Model\Scope;
use MattLeads\AgentPublishingBundle\Security\ApiKeyManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class SeoTest extends TestCase
{
    public function testSitemapAndLlmsTxtArePublicAndListPublishedArticles(): void
    {
        $kernel = new TestKernel(['storage' => 'memory', 'seo' => ['site_name' => 'Test Site', 'publisher' => ['name' => 'Test Ltd']]]);
        $kernel->boot();
        $container = $kernel->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);
        $keys = $container->get(ApiKeyManager::class);
        \assert($keys instanceof ApiKeyManager);
        $editor = $keys->issue('editor', [Scope::ArticlesDraft, Scope::ArticlesPublish])['secret'];

        $client = new KernelBrowser($kernel);
        $client->disableReboot();
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $editor];
        $client->request('POST', '/api/publisher/v1/articles', server: $server, content: json_encode(['section' => 'blog', 'locale' => 'en', 'title' => 'Hello', 'body' => 'x', 'summary' => 'Sum'], \JSON_THROW_ON_ERROR));
        /** @var array{id: string} $created */
        $created = json_decode((string) $client->getResponse()->getContent(), true, 64, \JSON_THROW_ON_ERROR);
        $client->request('POST', '/api/publisher/v1/articles/' . $created['id'] . '/publish', server: $server);

        $client->request('GET', '/sitemap-publisher.xml');
        self::assertSame(200, $client->getResponse()->getStatusCode(), 'no API key needed');
        self::assertStringStartsWith('application/xml', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('<loc>http://localhost/blog/hello</loc>', (string) $client->getResponse()->getContent(), 'base URL falls back to the request host');

        $client->request('GET', '/llms.txt');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringStartsWith("# Test Site\n\n## Blog\n\n- [Hello](http://localhost/blog/hello): Sum", (string) $client->getResponse()->getContent());

        $kernel->shutdown();
    }

    public function testDisabledOutputsReturn404(): void
    {
        $kernel = new TestKernel(['storage' => 'memory', 'seo' => ['sitemap' => ['enabled' => false], 'llms_txt' => ['enabled' => false]]]);
        $kernel->boot();
        $client = new KernelBrowser($kernel);
        $client->request('GET', '/sitemap-publisher.xml');
        self::assertSame(404, $client->getResponse()->getStatusCode());
        $client->request('GET', '/llms.txt');
        self::assertSame(404, $client->getResponse()->getStatusCode());
        $kernel->shutdown();
    }
}
