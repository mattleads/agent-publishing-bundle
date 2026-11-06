<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Mcp;

use MattLeads\AgentPublishingBundle\Model\ApiKey;

/**
 * Tools-only MCP server speaking JSON-RPC 2.0 over Streamable HTTP, without a protocol dependency. Supports
 * initialize, ping, tools/list and tools/call; notifications are acknowledged.
 */
final class McpServer
{
    public const PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];
    public const SERVER_NAME = 'agent-publishing-bundle';
    public const SERVER_VERSION = '0.3';

    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;

    /** @var array<string, McpToolInterface> */
    private readonly array $tools;

    /** @param iterable<McpToolInterface> $tools */
    public function __construct(iterable $tools, private readonly ?string $instructions = null)
    {
        $indexed = [];
        foreach ($tools as $tool) {
            $indexed[$tool->name()] = $tool;
        }
        $this->tools = $indexed;
    }

    /**
     * Handles one JSON-RPC message. Returns null for notifications (nothing to send back).
     *
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>|null
     */
    public function handle(array $message, ?ApiKey $key): ?array
    {
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? null;
        if (($message['jsonrpc'] ?? null) !== '2.0' || !\is_string($method)) {
            return self::error($id, self::INVALID_REQUEST, 'Invalid request: expected a JSON-RPC 2.0 message with a "method".');
        }
        if (!\array_key_exists('id', $message)) {
            return null; // a notification such as notifications/initialized or notifications/cancelled
        }
        /** @var array<string, mixed> $params */
        $params = \is_array($message['params'] ?? null) ? $message['params'] : [];

        return match ($method) {
            'initialize' => self::result($id, $this->initialize($params)),
            'ping' => self::result($id, new \stdClass()),
            'tools/list' => self::result($id, ['tools' => $this->toolList($key)]),
            'tools/call' => $this->call($id, $params, $key),
            default => self::error($id, self::METHOD_NOT_FOUND, \sprintf('Method "%s" is not supported. This server offers tools only.', $method)),
        };
    }

    /** Picks the client's protocol version when we support it, else our latest. */
    public static function negotiate(mixed $requested): string
    {
        return \is_string($requested) && \in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : self::PROTOCOL_VERSIONS[0];
    }

    /** @return list<string> */
    public function toolNames(): array
    {
        return array_keys($this->tools);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $result = [
            'protocolVersion' => self::negotiate($params['protocolVersion'] ?? null),
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => self::SERVER_NAME, 'version' => self::SERVER_VERSION],
        ];
        if (null !== $this->instructions) {
            $result['instructions'] = $this->instructions;
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function toolList(?ApiKey $key): array
    {
        $list = [];
        foreach ($this->tools as $tool) {
            if (null !== $key && !$key->hasScope($tool->scope())) {
                continue;
            }
            $list[] = ['name' => $tool->name(), 'description' => $tool->description(), 'inputSchema' => $tool->inputSchema()];
        }

        return $list;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function call(mixed $id, array $params, ?ApiKey $key): array
    {
        $name = $params['name'] ?? null;
        $tool = \is_string($name) ? ($this->tools[$name] ?? null) : null;
        if (null === $tool) {
            return self::error($id, self::INVALID_PARAMS, \sprintf('Unknown tool "%s".', \is_scalar($name) ? (string) $name : \gettype($name)));
        }
        if (null === $key) {
            return self::error($id, self::INVALID_REQUEST, 'An API key is required.');
        }
        if (!$key->hasScope($tool->scope())) {
            return self::toolError($id, \sprintf('This key lacks the "%s" scope needed by "%s".', $tool->scope()->value, $tool->name()));
        }
        /** @var array<string, mixed> $arguments */
        $arguments = \is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        try {
            $data = $tool->call($arguments, $key);
        } catch (ToolException $e) {
            return self::toolError($id, $e->getMessage(), $e->details);
        }

        return self::result($id, [
            'content' => [['type' => 'text', 'text' => self::json($data)]],
            'structuredContent' => $data,
            'isError' => false,
        ]);
    }

    /**
     * @param list<string> $details
     *
     * @return array<string, mixed>
     */
    private static function toolError(mixed $id, string $message, array $details = []): array
    {
        $text = $message . ([] === $details ? '' : "\n- " . implode("\n- ", $details));

        return self::result($id, [
            'content' => [['type' => 'text', 'text' => $text]],
            'structuredContent' => ['error' => $message, 'details' => $details],
            'isError' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private static function result(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return array<string, mixed> */
    public static function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    private static function json(mixed $data): string
    {
        return json_encode($data, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);
    }
}
