<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Tests\Functional;

use MattLeads\AgentPublishingBundle\Model\Scope;
use MattLeads\AgentPublishingBundle\Security\ApiKeyManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class LintTest extends TestCase
{
    private const PREFIX = '/api/publisher/v1';

    public function testLintEndpoints(): void
    {
        $kernel = new TestKernel(['storage' => 'memory']);
        $kernel->boot();
        $container = $kernel->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);
        $keys = $container->get(ApiKeyManager::class);
        \assert($keys instanceof ApiKeyManager);
        $agent = $keys->issue('agent', [Scope::ArticlesRead, Scope::ArticlesDraft], ['blog'])['secret'];

        $client = new KernelBrowser($kernel);
        $client->disableReboot();

        // Lint before creating: validation first.
        $this->api($client, 'POST', '/lint', $agent, ['section' => 'blog', 'locale' => 'en']);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());

        $this->api($client, 'POST', '/lint', $agent, ['section' => 'docs', 'locale' => 'en', 'title' => 'x', 'body' => 'y']);
        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode(), 'section restriction applies');

        $payload = ['section' => 'blog', 'locale' => 'en', 'title' => 'Short', 'body' => "## Heading first\n\nTwo words."];
        $this->api($client, 'POST', '/lint', $agent, $payload);
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $report = $this->json($client);
        self::assertSame('fail', $report['status']);
        self::assertArrayNotHasKey('article_id', $report);
        $rules = array_column($report['checks'], 'level', 'rule');
        self::assertSame('fail', $rules['summary']);
        self::assertSame('warn', $rules['title_length']);
        self::assertArrayNotHasKey('faq', $rules, 'blog does not require a FAQ');

        // Nothing was stored by the payload lint.
        $this->api($client, 'GET', '/articles', $agent);
        self::assertSame(0, $this->json($client)['total']);

        // Lint a stored draft, then fix it and lint again.
        $this->api($client, 'POST', '/articles', $agent, $payload);
        $id = $this->json($client)['id'];
        $this->api($client, 'POST', "/articles/$id/lint", $agent);
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $report = $this->json($client);
        self::assertSame($id, $report['article_id']);
        self::assertSame('fail', $report['status']);

        $this->api($client, 'PATCH', "/articles/$id", $agent, ['summary' => 'A summary long enough to serve as a meta description for this article about linting.']);
        $this->api($client, 'POST', "/articles/$id/lint", $agent);
        $rules = array_column($this->json($client)['checks'], 'level', 'rule');
        self::assertSame('pass', $rules['summary']);

        $this->api($client, 'POST', '/articles/nope/lint', $agent);
        self::assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());

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
