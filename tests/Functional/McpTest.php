<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Tests\Functional;

use MattLeads\AgentPublishingBundle\Mcp\McpServer;
use MattLeads\AgentPublishingBundle\Model\Scope;
use MattLeads\AgentPublishingBundle\Security\ApiKeyManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class McpTest extends TestCase
{
    private const MCP = '/api/publisher/v1/mcp';

    public function testHandshakeToolsAndAgentLoop(): void
    {
        $kernel = new TestKernel(['storage' => 'memory', 'mcp' => ['instructions' => 'Draft, lint, submit.', 'style_guide' => 'Be brief.']]);
        $kernel->boot();
        $container = $kernel->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);
        $keys = $container->get(ApiKeyManager::class);
        \assert($keys instanceof ApiKeyManager);
        $agent = $keys->issue('agent', [Scope::ArticlesRead, Scope::ArticlesDraft], ['blog'])['secret'];
        $editor = $keys->issue('editor', [Scope::ArticlesRead, Scope::ArticlesApprove, Scope::ArticlesPublish, Scope::StatsRead])['secret'];

        $client = new KernelBrowser($kernel);
        $client->disableReboot();

        // No key: the firewall's entry point answers.
        $client->request('POST', self::MCP, server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());

        // Handshake.
        $r = $this->rpc($client, $agent, 1, 'initialize', ['protocolVersion' => '2025-03-26', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']]);
        self::assertSame('2025-03-26', $r['result']['protocolVersion'], 'a supported client version is echoed');
        self::assertSame(['tools' => ['listChanged' => false]], $r['result']['capabilities']);
        self::assertSame(McpServer::SERVER_NAME, $r['result']['serverInfo']['name']);
        self::assertSame('Draft, lint, submit.', $r['result']['instructions']);
        self::assertSame('2025-06-18', $client->getResponse()->headers->get('MCP-Protocol-Version'), 'without a client header the latest version is announced');

        $client->request('POST', self::MCP, server: $this->server($agent) + ['HTTP_MCP_PROTOCOL_VERSION' => '2025-03-26'], content: '{"jsonrpc":"2.0","id":"v","method":"ping"}');
        self::assertSame('2025-03-26', $client->getResponse()->headers->get('MCP-Protocol-Version'), 'the negotiated version is echoed on later requests');

        $client->request('POST', self::MCP, server: $this->server($agent), content: '{"jsonrpc":"2.0","method":"notifications/initialized"}');
        self::assertSame(Response::HTTP_ACCEPTED, $client->getResponse()->getStatusCode(), 'notifications are acknowledged without a body');
        self::assertSame('', $client->getResponse()->getContent());

        $r = $this->rpc($client, $agent, 2, 'ping');
        self::assertSame([], $r['result']);

        // Tools are filtered by scope.
        $agentTools = array_column($this->rpc($client, $agent, 3, 'tools/list')['result']['tools'], 'name');
        self::assertContains('create_draft', $agentTools);
        self::assertContains('submit_article', $agentTools);
        self::assertNotContains('approve_article', $agentTools);
        self::assertNotContains('get_stats_summary', $agentTools);
        $editorTools = array_column($this->rpc($client, $editor, 4, 'tools/list')['result']['tools'], 'name');
        self::assertContains('approve_article', $editorTools);
        self::assertContains('get_top_referrers', $editorTools);
        self::assertNotContains('create_draft', $editorTools);
        foreach ($this->rpc($client, $editor, 5, 'tools/list')['result']['tools'] as $tool) {
            self::assertSame('object', $tool['inputSchema']['type'], $tool['name']);
            self::assertNotSame('', $tool['description']);
        }

        // The agent loop: sections, style guide, lint, create, lint again, submit.
        $sections = $this->call($client, $agent, 'list_sections');
        self::assertSame(['blog'], array_column($sections['structuredContent']['sections'], 'name'));
        self::assertSame('Be brief.', $this->call($client, $agent, 'get_style_guide')['structuredContent']['style_guide']);

        $draft = ['section' => 'blog', 'locale' => 'en', 'title' => 'Short', 'body' => 'Two words.'];
        $lint = $this->call($client, $agent, 'lint_draft', $draft);
        self::assertFalse($lint['isError']);
        self::assertSame('fail', $lint['structuredContent']['status']);
        self::assertStringContainsString('"status": "fail"', $lint['content'][0]['text']);

        $created = $this->call($client, $agent, 'create_draft', $draft + ['summary' => 'A summary long enough to serve as the meta description of this article.']);
        self::assertFalse($created['isError']);
        $id = $created['structuredContent']['id'];
        self::assertSame('draft', $created['structuredContent']['state']);

        $updated = $this->call($client, $agent, 'update_draft', ['id' => $id, 'tags' => ['symfony']]);
        self::assertSame(['symfony'], $updated['structuredContent']['tags']);
        self::assertSame('pass', array_column($this->call($client, $agent, 'lint_article', ['id' => $id])['structuredContent']['checks'], 'level', 'rule')['summary']);

        $submitted = $this->call($client, $agent, 'submit_article', ['id' => $id]);
        self::assertSame('in_review', $submitted['structuredContent']['state']);

        // Tool errors are results with isError, not protocol errors.
        $again = $this->call($client, $agent, 'submit_article', ['id' => $id]);
        self::assertTrue($again['isError']);
        self::assertStringContainsString('Cannot "submit"', $again['content'][0]['text']);
        $forbidden = $this->call($client, $agent, 'publish_article', ['id' => $id]);
        self::assertTrue($forbidden['isError']);
        self::assertStringContainsString('lacks the "articles:publish" scope', $forbidden['content'][0]['text']);
        $missing = $this->call($client, $agent, 'get_article', ['id' => 'nope']);
        self::assertTrue($missing['isError']);
        $invalid = $this->call($client, $agent, 'create_draft', ['section' => 'blog']);
        self::assertTrue($invalid['isError']);
        self::assertContains('Field "locale" is required.', $invalid['structuredContent']['details']);

        // The editor approves and reads stats.
        $approved = $this->call($client, $editor, 'approve_article', ['id' => $id]);
        self::assertSame('published', $approved['structuredContent']['state']);
        $stats = $this->call($client, $editor, 'get_article_stats', ['id' => $id]);
        self::assertSame(0, $stats['structuredContent']['views']);
        $summary = $this->call($client, $editor, 'get_stats_summary', ['section' => 'blog', 'from' => '2026-01-01', 'to' => '2026-01-31']);
        self::assertSame('2026-01-01', $summary['structuredContent']['from']);
        $bad = $this->call($client, $editor, 'get_top_referrers', ['from' => 'yesterday']);
        self::assertTrue($bad['isError']);

        // Protocol errors.
        $r = $this->rpc($client, $agent, 9, 'resources/list');
        self::assertSame(McpServer::METHOD_NOT_FOUND, $r['error']['code']);
        $r = $this->rpc($client, $agent, 10, 'tools/call', ['name' => 'teleport']);
        self::assertSame(McpServer::INVALID_PARAMS, $r['error']['code']);
        $client->request('POST', self::MCP, server: $this->server($agent), content: '{not json');
        self::assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());
        self::assertSame(McpServer::PARSE_ERROR, $this->json($client)['error']['code']);
        $client->request('POST', self::MCP, server: $this->server($agent), content: '[{"jsonrpc":"2.0","id":"a","method":"ping"},{"jsonrpc":"2.0","method":"notifications/initialized"}]');
        $batch = $this->json($client);
        self::assertCount(1, $batch, 'a batch answers only the requests');
        self::assertSame('a', $batch[0]['id']);
        $client->request('GET', self::MCP, server: $this->server($agent));
        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $client->getResponse()->getStatusCode());
        $client->request('DELETE', self::MCP, server: $this->server($agent));
        self::assertSame(Response::HTTP_NO_CONTENT, $client->getResponse()->getStatusCode());

        $kernel->shutdown();
    }

    public function testDisabled(): void
    {
        $kernel = new TestKernel(['storage' => 'memory', 'mcp' => ['enabled' => false]]);
        $kernel->boot();
        $container = $kernel->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);
        $keys = $container->get(ApiKeyManager::class);
        \assert($keys instanceof ApiKeyManager);
        $key = $keys->issue('k', [Scope::ArticlesRead])['secret'];
        $client = new KernelBrowser($kernel);
        $this->rpc($client, $key, 1, 'ping');
        self::assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());
        $kernel->shutdown();
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{content: list<array{type: string, text: string}>, structuredContent: array<string, mixed>, isError: bool}
     */
    private function call(KernelBrowser $client, string $secret, string $tool, array $arguments = []): array
    {
        static $n = 100;
        $r = $this->rpc($client, $secret, ++$n, 'tools/call', ['name' => $tool, 'arguments' => $arguments]);
        self::assertArrayHasKey('result', $r, (string) json_encode($r));

        /** @var array{content: list<array{type: string, text: string}>, structuredContent: array<string, mixed>, isError: bool} $result */
        $result = $r['result'];

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function rpc(KernelBrowser $client, string $secret, int|string $id, string $method, array $params = []): array
    {
        $message = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method];
        if ([] !== $params) {
            $message['params'] = $params;
        }
        $client->request('POST', self::MCP, server: $this->server($secret), content: json_encode($message, \JSON_THROW_ON_ERROR));
        if (Response::HTTP_NOT_FOUND === $client->getResponse()->getStatusCode()) {
            return [];
        }
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $r = $this->json($client);
        self::assertSame('2.0', $r['jsonrpc']);
        self::assertSame($id, $r['id']);

        return $r;
    }

    /** @return array<string, string> */
    private function server(string $secret): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $secret];
    }

    /** @return array<mixed> */
    private function json(KernelBrowser $client): array
    {
        $content = (string) $client->getResponse()->getContent();
        self::assertJson($content, 'Response is not JSON: ' . substr(strip_tags($content), 0, 300));
        /** @var array<mixed> $data */
        $data = json_decode($content, true, 64, \JSON_THROW_ON_ERROR);

        return $data;
    }
}
