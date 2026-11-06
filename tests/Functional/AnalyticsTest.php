<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use MattLeads\AgentPublishingBundle\Model\Scope;
use MattLeads\AgentPublishingBundle\Security\ApiKeyManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class AnalyticsTest extends TestCase
{
    private const PREFIX = '/api/publisher/v1';
    private const CHROME = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function storages(): iterable
    {
        yield 'memory' => [['storage' => 'memory']];
        yield 'doctrine' => [['storage' => 'doctrine']];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('storages')]
    public function testViewsAreCountedAndReported(array $config): void
    {
        $kernel = new TestKernel($config + ['analytics' => ['site_host' => 'localhost']]);
        $kernel->boot();
        $container = $kernel->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);
        if ($container->has('doctrine.orm.entity_manager')) {
            $em = $container->get('doctrine.orm.entity_manager');
            \assert($em instanceof EntityManagerInterface);
            (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        }
        $keys = $container->get(ApiKeyManager::class);
        \assert($keys instanceof ApiKeyManager);
        $editor = $keys->issue('editor', [Scope::ArticlesRead, Scope::ArticlesDraft, Scope::ArticlesPublish, Scope::StatsRead])['secret'];
        $noStats = $keys->issue('agent', [Scope::ArticlesRead])['secret'];

        $client = new KernelBrowser($kernel);
        $client->disableReboot();

        // A published article and a draft with their own slugs.
        $this->api($client, 'POST', '/articles', $editor, ['section' => 'blog', 'locale' => 'en', 'title' => 'Hello', 'body' => 'x']);
        $id = $this->json($client)['id'];
        $this->api($client, 'POST', "/articles/$id/publish", $editor);
        $this->api($client, 'POST', '/articles', $editor, ['section' => 'blog', 'locale' => 'en', 'title' => 'Draft only', 'body' => 'x']);

        // Views of the host page with different origins.
        $this->visit($client, '/blog/hello', self::CHROME, null);
        $this->visit($client, '/blog/hello', self::CHROME, 'https://www.google.com/');
        $this->visit($client, '/blog/hello', self::CHROME, 'https://www.google.com/');
        $this->visit($client, '/blog/hello', self::CHROME, 'https://chatgpt.com/c/1');
        $this->visit($client, '/blog/hello', self::CHROME, 'https://localhost/blog');
        $this->visit($client, '/blog/hello', 'Mozilla/5.0 (compatible; GPTBot/1.1)', null);
        $this->visit($client, '/blog/hello', 'curl/8.0', null);
        $this->visit($client, '/blog/draft-only', self::CHROME, null);      // draft: not counted
        $this->visit($client, '/blog/missing', self::CHROME, null);         // 404: not counted
        $this->visit($client, '/blog/nope', self::CHROME, null);            // unknown slug: not counted

        // Stats need the scope.
        $this->api($client, 'GET', "/articles/$id/stats", $noStats);
        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());

        $this->api($client, 'GET', "/articles/$id/stats", $editor);
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $stats = $this->json($client);
        self::assertSame(5, $stats['views']);
        self::assertSame(2, $stats['crawler_views']);
        self::assertSame(['ai_crawler' => 1, 'ai_referral' => 1, 'bot' => 1, 'direct' => 1, 'internal' => 1, 'search' => 2], $stats['by_kind']);
        self::assertCount(1, $stats['per_day']);
        self::assertSame(['views' => 5, 'crawler_views' => 2], array_intersect_key($stats['per_day'][0], ['views' => 1, 'crawler_views' => 1]));
        self::assertSame(['host' => 'google.com', 'kind' => 'search', 'views' => 2], $stats['referrers'][0]);
        self::assertContains(['host' => 'chatgpt.com', 'kind' => 'ai_referral', 'views' => 1], $stats['referrers']);

        $this->api($client, 'GET', '/stats/summary?section=blog', $editor);
        $summary = $this->json($client);
        self::assertSame('blog', $summary['section']);
        self::assertSame(5, $summary['views']);
        self::assertSame(2, $summary['crawler_views']);

        $this->api($client, 'GET', '/stats/summary?section=docs', $editor);
        self::assertSame(0, $this->json($client)['views']);

        $this->api($client, 'GET', '/stats/referrers?limit=2', $editor);
        $referrers = $this->json($client)['items'];
        self::assertCount(2, $referrers);
        self::assertSame('google.com', $referrers[0]['host']);

        // Period validation.
        $this->api($client, 'GET', '/stats/summary?from=2026-13-01', $editor);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
        $this->api($client, 'GET', '/stats/summary?from=2026-02-01&to=2026-01-01', $editor);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
        $this->api($client, 'GET', '/stats/summary?from=2020-01-01&to=2020-12-31', $editor);
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        self::assertSame(0, $this->json($client)['views'], 'nothing in an old period');
        $this->api($client, 'GET', '/stats/summary?section=nope', $editor);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());

        $kernel->shutdown();
    }

    private function visit(KernelBrowser $client, string $path, ?string $userAgent, ?string $referrer): void
    {
        // BrowserKit sends the previous URL as Referer unless told otherwise, so "no referrer" is an explicit empty header.
        $server = ['HTTP_REFERER' => $referrer ?? ''];
        if (null !== $userAgent) {
            $server['HTTP_USER_AGENT'] = $userAgent;
        }
        $client->request('GET', $path, server: $server);
    }

    /** @param array<string, mixed>|null $body */
    private function api(KernelBrowser $client, string $method, string $path, string $secret, ?array $body = null): void
    {
        $client->request($method, self::PREFIX . $path, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $secret], content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        $content = (string) $client->getResponse()->getContent();
        self::assertJson($content, 'Response is not JSON: ' . substr(strip_tags($content), 0, 300));
        /** @var array<string, mixed> $data */
        $data = json_decode($content, true, 64, \JSON_THROW_ON_ERROR);

        return $data;
    }
}
