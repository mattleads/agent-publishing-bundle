<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use MattLeads\AgentPublishingBundle\Model\Scope;
use MattLeads\AgentPublishingBundle\Security\ApiKeyManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class ApiFlowTest extends TestCase
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
    public function testDraftReviewPublishFlow(array $config): void
    {
        $kernel = new TestKernel($config);
        $kernel->boot();
        $this->prepareSchema($kernel);
        $container = $kernel->getContainer()->get('test.service_container');
        \assert($container instanceof \Psr\Container\ContainerInterface);
        $keys = $container->get(ApiKeyManager::class);
        \assert($keys instanceof ApiKeyManager);

        $agent = $keys->issue('agent', [Scope::ArticlesRead, Scope::ArticlesDraft], ['blog'])['secret'];
        $editor = $keys->issue('editor', [Scope::ArticlesRead, Scope::ArticlesApprove, Scope::ArticlesPublish])['secret'];

        $client = new KernelBrowser($kernel);
        $client->disableReboot();

        // No key: 401 JSON from the entry point.
        $this->call($client, 'GET', '/sections', null);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
        self::assertSame('API key required. Send "Authorization: Bearer apb_...".', $this->json($client)['error']);

        // Wrong key: 401.
        $this->call($client, 'GET', '/sections', 'apb_nope');
        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());

        // Sections are filtered to what the key may access.
        $this->call($client, 'GET', '/sections', $agent);
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        self::assertSame([['name' => 'blog', 'path' => '/blog', 'locales' => ['en', 'de']]], $this->json($client)['items']);

        // Validation.
        $this->call($client, 'POST', '/articles', $agent, ['section' => 'blog', 'locale' => 'en']);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
        self::assertContains('Field "title" is required.', $this->json($client)['violations']);

        $this->call($client, 'POST', '/articles', $agent, ['section' => 'docs', 'locale' => 'en', 'title' => 'x', 'body' => 'y']);
        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode(), 'key is restricted to blog');

        $this->call($client, 'POST', '/articles', $agent, ['section' => 'blog', 'locale' => 'fr', 'title' => 'x', 'body' => 'y']);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());

        // Create a draft; the slug derives from the title.
        $this->call($client, 'POST', '/articles', $agent, ['section' => 'blog', 'locale' => 'en', 'title' => 'Hello, Wörld!', 'body' => '# Hi', 'tags' => ['a', 'a', 'b']]);
        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode());
        $article = $this->json($client);
        self::assertSame('hello-world', $article['slug']);
        self::assertSame('draft', $article['state']);
        self::assertSame('markdown', $article['format']);
        self::assertSame(['a', 'b'], $article['tags']);
        self::assertSame('agent', $article['created_by']);
        self::assertSame(['submit', 'publish'], $article['transitions']);
        $id = $article['id'];

        // Same title again gets a numbered slug.
        $this->call($client, 'POST', '/articles', $agent, ['section' => 'blog', 'locale' => 'en', 'title' => 'Hello, Wörld!', 'body' => 'again']);
        self::assertSame('hello-world-2', $this->json($client)['slug']);

        // Update while in draft.
        $this->call($client, 'PATCH', "/articles/$id", $agent, ['summary' => 'A summary', 'slug' => 'hello']);
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        self::assertSame('hello', $this->json($client)['slug']);

        // The agent may submit but not approve or publish.
        $this->call($client, 'POST', "/articles/$id/publish", $agent);
        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
        $this->call($client, 'POST', "/articles/$id/submit", $agent);
        self::assertSame('in_review', $this->json($client)['state']);
        $this->call($client, 'POST', "/articles/$id/submit", $agent);
        self::assertSame(Response::HTTP_CONFLICT, $client->getResponse()->getStatusCode());

        // The editor rejects with a reason, the agent fixes and resubmits, the editor approves.
        $this->call($client, 'POST', "/articles/$id/reject", $editor, ['reason' => 'Needs a summary']);
        $rejected = $this->json($client);
        self::assertSame('draft', $rejected['state']);
        self::assertSame('Needs a summary', $rejected['review_note']);
        $this->call($client, 'POST', "/articles/$id/submit", $agent);
        $this->call($client, 'POST', "/articles/$id/approve", $editor);
        $published = $this->json($client);
        self::assertSame('published', $published['state']);
        self::assertNotNull($published['published_at']);
        self::assertNull($published['review_note']);

        // Published articles are frozen for editing.
        $this->call($client, 'PATCH', "/articles/$id", $agent, ['title' => 'Changed']);
        self::assertSame(Response::HTTP_CONFLICT, $client->getResponse()->getStatusCode());

        // Scheduling moves to "scheduled"; archiving ends it.
        $this->call($client, 'POST', '/articles', $agent, ['section' => 'blog', 'locale' => 'de', 'title' => 'Später', 'body' => 'x']);
        $later = $this->json($client)['id'];
        $this->call($client, 'POST', "/articles/$later/publish", $editor, ['publish_at' => '2099-01-01T09:00:00+00:00']);
        $scheduled = $this->json($client);
        self::assertSame('scheduled', $scheduled['state']);
        self::assertSame('2099-01-01T09:00:00+00:00', $scheduled['publish_at']);
        $this->call($client, 'POST', "/articles/$later/archive", $editor);
        self::assertSame('archived', $this->json($client)['state']);

        // Listing with filters and pagination.
        $this->call($client, 'GET', '/articles?state=published', $editor);
        $list = $this->json($client);
        self::assertSame(1, $list['total']);
        self::assertSame($id, $list['items'][0]['id']);
        $this->call($client, 'GET', '/articles?locale=en&per_page=1', $editor);
        $list = $this->json($client);
        self::assertSame(2, $list['total']);
        self::assertCount(1, $list['items']);
        $this->call($client, 'GET', '/articles?state=bogus', $editor);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());

        $this->call($client, 'GET', '/articles/nope', $editor);
        self::assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());

        $kernel->shutdown();
    }

    /** @param array<string, mixed>|null $body */
    private function call(KernelBrowser $client, string $method, string $path, ?string $secret, ?array $body = null): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $secret) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $secret;
        }
        $client->request($method, self::PREFIX . $path, server: $server, content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        $content = (string) $client->getResponse()->getContent();
        self::assertJson($content, 'Response is not JSON: ' . substr($content, 0, 300));
        /** @var array<string, mixed> $data */
        $data = json_decode($content, true, 64, \JSON_THROW_ON_ERROR);

        return $data;
    }

    private function prepareSchema(TestKernel $kernel): void
    {
        if (!$kernel->getContainer()->has('doctrine.orm.entity_manager')) {
            return;
        }
        $em = $kernel->getContainer()->get('doctrine.orm.entity_manager');
        \assert($em instanceof EntityManagerInterface);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
    }
}
