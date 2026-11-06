<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Tests\Functional;

use MattLeads\AgentPublishingBundle\Model\Scope;
use MattLeads\AgentPublishingBundle\Security\ApiKeyManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

/** Section restrictions must not leak the existence, counts or stats of articles in other sections. */
final class AuthorizationTest extends TestCase
{
    private const PREFIX = '/api/publisher/v1';

    public function testSectionRestrictedKeysCannotSeeOtherSections(): void
    {
        $kernel = new TestKernel(['storage' => 'memory']);
        $kernel->boot();
        $container = $kernel->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);
        $keys = $container->get(ApiKeyManager::class);
        \assert($keys instanceof ApiKeyManager);
        $all = Scope::cases();
        $editor = $keys->issue('editor', $all)['secret'];
        $blogOnly = $keys->issue('blog-only', $all, ['blog'])['secret'];
        $both = $keys->issue('both', $all, ['blog', 'docs'])['secret'];

        $client = new KernelBrowser($kernel);
        $client->disableReboot();

        $this->api($client, 'POST', '/articles', $editor, ['section' => 'blog', 'locale' => 'en', 'title' => 'Blog post', 'body' => 'x']);
        $blogId = $this->json($client)['id'];
        $this->api($client, 'POST', '/articles', $editor, ['section' => 'docs', 'locale' => 'en', 'title' => 'Docs page', 'body' => 'x']);
        $docsId = $this->json($client)['id'];

        // Listing: a single-section key is scoped automatically, totals included.
        $this->api($client, 'GET', '/articles', $blogOnly);
        $list = $this->json($client);
        self::assertSame(1, $list['total']);
        self::assertSame($blogId, $list['items'][0]['id']);
        $this->api($client, 'GET', '/articles?section=docs', $blogOnly);
        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());

        // A multi-section key must choose.
        $this->api($client, 'GET', '/articles', $both);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('pass "section"', $this->json($client)['violations'][0]);
        $this->api($client, 'GET', '/articles?section=docs', $both);
        self::assertSame(1, $this->json($client)['total']);

        // An unrestricted key sees everything.
        $this->api($client, 'GET', '/articles', $editor);
        self::assertSame(2, $this->json($client)['total']);

        // Objects in other sections look non-existent.
        foreach (['GET' => "/articles/$docsId", 'PATCH' => "/articles/$docsId", 'POST' => "/articles/$docsId/submit", 'LINT' => "/articles/$docsId/lint", 'STATS' => "/articles/$docsId/stats"] as $method => $path) {
            $this->api($client, match ($method) {
                'LINT' => 'POST', 'STATS' => 'GET', default => $method
            }, $path, $blogOnly, 'PATCH' === $method ? ['title' => 'x'] : null);
            self::assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode(), "$method $path");
        }
        $this->api($client, 'GET', "/articles/$blogId", $blogOnly);
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        // Aggregates follow the same rule.
        $this->api($client, 'GET', '/stats/summary', $blogOnly);
        self::assertSame('blog', $this->json($client)['section']);
        $this->api($client, 'GET', '/stats/referrers', $both);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
        $this->api($client, 'GET', '/stats/summary?section=docs', $blogOnly);
        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
        $this->api($client, 'GET', '/stats/summary', $editor);
        self::assertNull($this->json($client)['section']);

        // Tag limits.
        $this->api($client, 'POST', '/articles', $editor, ['section' => 'blog', 'locale' => 'en', 'title' => 'T', 'body' => 'x', 'tags' => array_map(static fn(int $i) => "t$i", range(1, 21))]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
        $this->api($client, 'POST', '/articles', $editor, ['section' => 'blog', 'locale' => 'en', 'title' => 'T', 'body' => 'x', 'tags' => [str_repeat('a', 65)]]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());

        $kernel->shutdown();
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
