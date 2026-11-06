<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Tests\Functional;

use MattLeads\AgentPublishingBundle\Model\Scope;
use MattLeads\AgentPublishingBundle\Security\ApiKeyManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class KeyManagementTest extends TestCase
{
    private const PREFIX = '/api/publisher/v1';

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function storages(): iterable
    {
        yield 'memory' => [['storage' => 'memory']];
        yield 'doctrine' => [['storage' => 'doctrine']];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('storages')]
    public function testIssueUseRevokeAndLimits(array $config): void
    {
        $kernel = new TestKernel($config);
        $kernel->boot();
        $container = $kernel->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);
        if ($container->has('doctrine.orm.entity_manager')) {
            $em = $container->get('doctrine.orm.entity_manager');
            \assert($em instanceof \Doctrine\ORM\EntityManagerInterface);
            (new \Doctrine\ORM\Tools\SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        }
        $keys = $container->get(ApiKeyManager::class);
        \assert($keys instanceof ApiKeyManager);
        $admin = $keys->issue('admin', Scope::cases())['secret'];
        $platform = $keys->issue('platform', [Scope::KeysManage, Scope::ArticlesRead, Scope::ArticlesDraft], ['blog'])['secret'];
        $noManage = $keys->issue('agent', [Scope::ArticlesRead])['secret'];

        $client = new KernelBrowser($kernel);
        $client->disableReboot();

        // Scope required.
        $this->api($client, 'GET', '/keys', $noManage);
        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());

        // The admin issues a key; the secret comes back once and works immediately.
        $this->api($client, 'POST', '/keys', $admin, ['name' => 'seo-agent', 'scopes' => ['articles:read', 'articles:draft'], 'sections' => ['docs'], 'expires_at' => '2099-01-01T00:00:00+00:00']);
        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode());
        $created = $this->json($client);
        self::assertStringStartsWith('apb_', $created['secret']);
        self::assertSame(['articles:read', 'articles:draft'], $created['scopes']);
        self::assertSame(['docs'], $created['sections']);
        self::assertSame('2099-01-01T00:00:00+00:00', $created['expires_at']);
        self::assertTrue($created['active']);
        $agentId = $created['id'];
        $agentSecret = $created['secret'];
        $this->api($client, 'GET', '/sections', $agentSecret);
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        // Reads never expose the secret.
        $this->api($client, 'GET', "/keys/$agentId", $admin);
        $read = $this->json($client);
        self::assertArrayNotHasKey('secret', $read);
        self::assertArrayNotHasKey('hash', $read);
        self::assertNotNull($read['last_used_at']);

        // Validation and escalation limits for the platform key.
        $this->api($client, 'POST', '/keys', $platform, ['name' => 'x', 'scopes' => ['articles:publish'], 'sections' => ['blog']]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('cannot grant', $this->json($client)['violations'][0]);
        $this->api($client, 'POST', '/keys', $platform, ['name' => 'x', 'scopes' => ['articles:read'], 'sections' => ['docs']]);
        self::assertStringContainsString('cannot access section "docs"', $this->json($client)['violations'][0]);
        $this->api($client, 'POST', '/keys', $platform, ['name' => 'x', 'scopes' => ['articles:read']]);
        self::assertStringContainsString('must name a subset', $this->json($client)['violations'][0]);
        $this->api($client, 'POST', '/keys', $admin, ['name' => '', 'scopes' => ['nope'], 'expires_at' => '2000-01-01T00:00:00+00:00', 'extra' => 1]);
        $violations = $this->json($client)['violations'];
        self::assertCount(4, $violations, implode(' | ', $violations));

        // The platform issues a key inside its sections and sees only such keys.
        $this->api($client, 'POST', '/keys', $platform, ['name' => 'customer-bot', 'scopes' => ['articles:draft'], 'sections' => ['blog']]);
        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode());
        $customerId = $this->json($client)['id'];
        $this->api($client, 'GET', '/keys', $platform);
        $names = array_column($this->json($client)['items'], 'name');
        self::assertContains('customer-bot', $names);
        self::assertContains('platform', $names);
        self::assertNotContains('admin', $names, 'unrestricted keys are invisible to a restricted manager');
        self::assertNotContains('seo-agent', $names, 'keys in other sections are invisible');
        $this->api($client, 'GET', "/keys/$agentId", $platform);
        self::assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());
        $this->api($client, 'DELETE', "/keys/$agentId", $platform);
        self::assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());

        // The admin sees everything and revokes the agent; the agent's next call fails.
        $this->api($client, 'GET', '/keys', $admin);
        self::assertGreaterThanOrEqual(5, $this->json($client)['total']);
        $this->api($client, 'DELETE', "/keys/$agentId", $admin);
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        self::assertNotNull($this->json($client)['revoked_at']);
        self::assertFalse($this->json($client)['active']);
        $this->api($client, 'GET', '/sections', $agentSecret);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());

        // The platform revokes its own customer key.
        $this->api($client, 'DELETE', "/keys/$customerId", $platform);
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

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
