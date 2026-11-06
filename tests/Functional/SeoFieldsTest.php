<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use MattLeads\AgentPublishingBundle\Contract\ArticleStorageInterface;
use MattLeads\AgentPublishingBundle\Model\Scope;
use MattLeads\AgentPublishingBundle\Security\ApiKeyManager;
use MattLeads\AgentPublishingBundle\Seo\JsonLdBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;

/**
 * 0.3: meta fields, cover image, authors, live editing, former slugs, sort by publication.
 */
final class SeoFieldsTest extends TestCase
{
    private const PREFIX = '/api/publisher/v1';

    private const AUTHORS = [
        'matt' => ['name' => 'Matt M', 'url' => 'https://example.com/about', 'image' => 'https://example.com/matt.jpg', 'bio' => 'Writes things.', 'same_as' => ['https://github.com/mattleads']],
    ];

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function storages(): iterable
    {
        yield 'memory' => [['storage' => 'memory']];
        yield 'doctrine' => [['storage' => 'doctrine']];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('storages')]
    public function testFieldsAuthorsAndJsonLd(array $config): void
    {
        [$kernel, $client, $container] = $this->boot($config + [
            'authors' => self::AUTHORS,
            'seo' => ['base_url' => 'https://example.com', 'publisher' => ['name' => 'Example', 'url' => 'https://example.com']],
        ]);
        $keys = $container->get(ApiKeyManager::class);
        \assert($keys instanceof ApiKeyManager);
        $agent = $keys->issue('agent', [Scope::ArticlesRead, Scope::ArticlesDraft])['secret'];

        $this->call($client, 'GET', '/authors', $agent);
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        self::assertSame(['items' => [['key' => 'matt'] + self::AUTHORS['matt']], 'default' => 'organization'], $this->json($client));

        $base = ['section' => 'blog', 'locale' => 'en', 'title' => 'Hello', 'body' => 'Text'];
        $this->call($client, 'POST', '/articles', $agent, $base + ['author' => 'bob']);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
        self::assertSame(['Field "author" must be one of: matt.'], $this->json($client)['violations']);

        $this->call($client, 'POST', '/articles', $agent, $base + ['cover_image' => '/relative.jpg']);
        self::assertSame(['Field "cover_image" must be an absolute http(s) URL.'], $this->json($client)['violations']);

        $this->call($client, 'POST', '/articles', $agent, $base + [
            'summary' => 'The summary.',
            'meta_title' => 'Meta title',
            'meta_description' => 'Meta description.',
            'cover_image' => 'https://cdn.example.org/c.jpg',
            'cover_image_alt' => 'A cover',
            'author' => 'matt',
        ]);
        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode());
        $article = $this->json($client);
        self::assertSame(['Meta title', 'Meta description.', 'https://cdn.example.org/c.jpg', 'A cover', 'matt', []], [$article['meta_title'], $article['meta_description'], $article['cover_image'], $article['cover_image_alt'], $article['author'], $article['former_slugs']]);

        $storage = $container->get(ArticleStorageInterface::class);
        \assert($storage instanceof ArticleStorageInterface);
        $jsonLd = $container->get(JsonLdBuilder::class);
        \assert($jsonLd instanceof JsonLdBuilder);
        $stored = $storage->find((string) $article['id']);
        \assert(null !== $stored);
        $data = $jsonLd->build($stored);
        self::assertSame('Meta description.', $data['description']);
        self::assertSame('https://cdn.example.org/c.jpg', $data['image']);
        self::assertSame(['@type' => 'Person', 'name' => 'Matt M', 'url' => 'https://example.com/about', 'image' => 'https://example.com/matt.jpg', 'sameAs' => ['https://github.com/mattleads']], $data['author']);

        // Clearing the author credits the publisher organisation.
        $this->call($client, 'PATCH', '/articles/' . $article['id'], $agent, ['author' => '']);
        self::assertNull($this->json($client)['author']);
        $stored = $storage->find((string) $article['id']);
        \assert(null !== $stored);
        self::assertSame(['@type' => 'Organization', 'name' => 'Example', 'url' => 'https://example.com'], $jsonLd->build($stored)['author']);

        $kernel->shutdown();
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('storages')]
    public function testPublishedArticlesStayLockedByDefault(array $config): void
    {
        [$kernel, $client, $container] = $this->boot($config);
        $keys = $container->get(ApiKeyManager::class);
        \assert($keys instanceof ApiKeyManager);
        $editor = $keys->issue('editor', [Scope::ArticlesRead, Scope::ArticlesDraft, Scope::ArticlesPublish])['secret'];

        $id = $this->published($client, $editor, 'locked');
        $this->call($client, 'PATCH', '/articles/' . $id, $editor, ['title' => 'Changed']);
        self::assertSame(Response::HTTP_CONFLICT, $client->getResponse()->getStatusCode());

        $kernel->shutdown();
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('storages')]
    public function testLiveEditingAndFormerSlugs(array $config): void
    {
        [$kernel, $client, $container] = $this->boot($config + ['editing' => ['published' => true]]);
        $keys = $container->get(ApiKeyManager::class);
        \assert($keys instanceof ApiKeyManager);
        $drafter = $keys->issue('drafter', [Scope::ArticlesRead, Scope::ArticlesDraft])['secret'];
        $editor = $keys->issue('editor', [Scope::ArticlesRead, Scope::ArticlesDraft, Scope::ArticlesPublish])['secret'];
        $storage = $container->get(ArticleStorageInterface::class);
        \assert($storage instanceof ArticleStorageInterface);

        $id = $this->published($client, $editor, 'first');

        // A drafting key never changes what readers see.
        $this->call($client, 'PATCH', '/articles/' . $id, $drafter, ['title' => 'Changed']);
        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());

        $this->call($client, 'PATCH', '/articles/' . $id, $editor, ['slug' => 'second']);
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        self::assertSame(['second', ['first']], [$this->json($client)['slug'], $this->json($client)['former_slugs']]);

        $this->call($client, 'PATCH', '/articles/' . $id, $editor, ['slug' => 'third']);
        self::assertSame(['first', 'second'], $this->json($client)['former_slugs']);
        // Every former slug resolves to the same article, whose current slug is the newest: one hop.
        self::assertSame('third', $storage->findByFormerSlug('blog', 'en', 'first')?->getSlug());
        self::assertSame('third', $storage->findByFormerSlug('blog', 'en', 'second')?->getSlug());
        self::assertNull($storage->findByFormerSlug('blog', 'en', 'third'));

        // A new article may take a former slug; the redirect goes away.
        $this->call($client, 'POST', '/articles', $editor, ['section' => 'blog', 'locale' => 'en', 'title' => 'Other', 'slug' => 'first', 'body' => 'x']);
        self::assertSame('first', $this->json($client)['slug']);
        self::assertNull($storage->findByFormerSlug('blog', 'en', 'first'));

        // Renaming back to an own former slug forgets it too.
        $this->call($client, 'PATCH', '/articles/' . $id, $editor, ['slug' => 'second']);
        self::assertSame(['third'], $this->json($client)['former_slugs']);
        self::assertNull($storage->findByFormerSlug('blog', 'en', 'second'));

        // Renaming a draft records nothing.
        $this->call($client, 'POST', '/articles', $editor, ['section' => 'blog', 'locale' => 'en', 'title' => 'Draft', 'slug' => 'draft-a', 'body' => 'x']);
        $draft = (string) $this->json($client)['id'];
        $this->call($client, 'PATCH', '/articles/' . $draft, $editor, ['slug' => 'draft-b']);
        self::assertSame([], $this->json($client)['former_slugs']);

        $kernel->shutdown();
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('storages')]
    public function testSortByPublication(array $config): void
    {
        [$kernel, $client, $container] = $this->boot($config + ['editing' => ['published' => true]]);
        $keys = $container->get(ApiKeyManager::class);
        \assert($keys instanceof ApiKeyManager);
        $editor = $keys->issue('editor', [Scope::ArticlesRead, Scope::ArticlesDraft, Scope::ArticlesPublish])['secret'];

        $older = $this->published($client, $editor, 'older');
        usleep(1100000);
        $newer = $this->published($client, $editor, 'newer');
        usleep(1100000);
        // Editing the older article makes it the most recently updated, not the most recently published.
        $this->call($client, 'PATCH', '/articles/' . $older, $editor, ['title' => 'Older, edited']);

        $this->call($client, 'GET', '/articles?state=published', $editor);
        self::assertSame([$older, $newer], array_column($this->json($client)['items'], 'id'));
        $this->call($client, 'GET', '/articles?state=published&sort=published', $editor);
        self::assertSame([$newer, $older], array_column($this->json($client)['items'], 'id'));
        $this->call($client, 'GET', '/articles?sort=random', $editor);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());

        $kernel->shutdown();
    }

    public function testMcpAuthorsAndLiveEditScope(): void
    {
        [$kernel, $client, $container] = $this->boot(['storage' => 'memory', 'authors' => self::AUTHORS, 'editing' => ['published' => true]]);
        $keys = $container->get(ApiKeyManager::class);
        \assert($keys instanceof ApiKeyManager);
        $drafter = $keys->issue('drafter', [Scope::ArticlesRead, Scope::ArticlesDraft])['secret'];
        $editor = $keys->issue('editor', [Scope::ArticlesRead, Scope::ArticlesDraft, Scope::ArticlesPublish])['secret'];

        $authors = $this->tool($client, $drafter, 'list_authors');
        self::assertFalse($authors['isError']);
        self::assertSame('matt', $authors['structuredContent']['items'][0]['key']);

        $draft = $this->tool($client, $drafter, 'create_draft', ['section' => 'blog', 'locale' => 'en', 'title' => 'Via MCP', 'body' => 'x', 'author' => 'matt', 'meta_description' => 'Short.']);
        self::assertFalse($draft['isError']);
        self::assertSame(['matt', 'Short.'], [$draft['structuredContent']['author'], $draft['structuredContent']['meta_description']]);

        $id = $this->published($client, $editor, 'live');
        $denied = $this->tool($client, $drafter, 'update_draft', ['id' => $id, 'title' => 'Nope']);
        self::assertTrue($denied['isError']);
        self::assertStringContainsString('articles:publish', $denied['content'][0]['text']);
        $allowed = $this->tool($client, $editor, 'update_draft', ['id' => $id, 'slug' => 'still-live']);
        self::assertFalse($allowed['isError']);
        self::assertSame(['live'], $allowed['structuredContent']['former_slugs']);

        $kernel->shutdown();
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{content: list<array{type: string, text: string}>, structuredContent: array<string, mixed>, isError: bool}
     */
    private function tool(KernelBrowser $client, string $secret, string $name, array $arguments = []): array
    {
        $message = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]];
        $client->request('POST', self::PREFIX . '/mcp', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $secret], content: json_encode($message, \JSON_THROW_ON_ERROR));
        $response = $this->json($client);
        self::assertArrayHasKey('result', $response, (string) json_encode($response));
        /** @var array{content: list<array{type: string, text: string}>, structuredContent: array<string, mixed>, isError: bool} $result */
        $result = $response['result'];

        return $result;
    }

    public function testStorageCanRefuseATransition(): void
    {
        [$kernel, $client, $container] = $this->boot(['storage' => 'memory'], refusePublishing: true);
        $keys = $container->get(ApiKeyManager::class);
        \assert($keys instanceof ApiKeyManager);
        $editor = $keys->issue('editor', [Scope::ArticlesRead, Scope::ArticlesDraft, Scope::ArticlesPublish])['secret'];

        $this->call($client, 'POST', '/articles', $editor, ['section' => 'blog', 'locale' => 'en', 'title' => 'Refused', 'body' => 'x']);
        $id = (string) $this->json($client)['id'];
        $this->call($client, 'POST', '/articles/' . $id . '/publish', $editor, []);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
        self::assertSame(['Image https://example.org/x.png: not found.'], $this->json($client)['violations']);

        $viaMcp = $this->tool($client, $editor, 'publish_article', ['id' => $id]);
        self::assertTrue($viaMcp['isError']);
        self::assertSame(['Image https://example.org/x.png: not found.'], $viaMcp['structuredContent']['details']);

        $this->call($client, 'GET', '/articles/' . $id, $editor);
        self::assertSame('draft', $this->json($client)['state']);

        $kernel->shutdown();
    }

    public function testKeyListAndRevokeCommands(): void
    {
        [$kernel, $client, $container] = $this->boot(['storage' => 'memory']);
        $keys = $container->get(ApiKeyManager::class);
        \assert($keys instanceof ApiKeyManager);
        $issued = $keys->issue('seo-agent', [Scope::ArticlesRead], ['blog']);

        $application = new Application($kernel);
        $list = new CommandTester($application->find('agent-publishing:api-key:list'));
        $list->execute([]);
        $listing = $list->getDisplay();
        self::assertStringContainsString('seo-agent', $listing);
        self::assertStringContainsString('articles:read', $listing);
        self::assertStringNotContainsString($issued['secret'], $listing);

        $this->call($client, 'GET', '/sections', $issued['secret']);
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $revoke = new CommandTester($application->find('agent-publishing:api-key:revoke'));
        self::assertSame(0, $revoke->execute(['key' => 'seo-agent']));
        $this->call($client, 'GET', '/sections', $issued['secret']);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());

        self::assertSame(1, $revoke->execute(['key' => 'nobody']));

        $kernel->shutdown();
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{TestKernel, KernelBrowser, ContainerInterface}
     */
    private function boot(array $config, bool $refusePublishing = false): array
    {
        $kernel = new TestKernel($config, $refusePublishing);
        $kernel->boot();
        if ($kernel->getContainer()->has('doctrine.orm.entity_manager')) {
            $em = $kernel->getContainer()->get('doctrine.orm.entity_manager');
            \assert($em instanceof EntityManagerInterface);
            (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        }
        $container = $kernel->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);
        $client = new KernelBrowser($kernel);
        $client->disableReboot();

        return [$kernel, $client, $container];
    }

    private function published(KernelBrowser $client, string $secret, string $slug): string
    {
        $this->call($client, 'POST', '/articles', $secret, ['section' => 'blog', 'locale' => 'en', 'title' => ucfirst($slug), 'slug' => $slug, 'body' => 'x']);
        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $id = (string) $this->json($client)['id'];
        $this->call($client, 'POST', '/articles/' . $id . '/publish', $secret, []);
        self::assertSame('published', $this->json($client)['state']);

        return $id;
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
}
