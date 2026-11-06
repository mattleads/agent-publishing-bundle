<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Mcp;

use MattLeads\AgentPublishingBundle\Api\ApiAccess;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streamable HTTP transport: POST carries JSON-RPC messages, GET (server-initiated stream) is not offered, DELETE
 * ends a session, which is a no-op because the server keeps no session state.
 */
final class McpController
{
    public function __construct(private readonly McpServer $server, private readonly ApiAccess $access, private readonly bool $enabled = true) {}

    public function endpoint(Request $request): Response
    {
        if (!$this->enabled) {
            return new JsonResponse(['error' => 'The MCP endpoint is disabled on this site.'], Response::HTTP_NOT_FOUND);
        }
        $headers = ['MCP-Protocol-Version' => McpServer::negotiate($request->headers->get('MCP-Protocol-Version'))];

        if ($request->isMethod('DELETE')) {
            return new Response('', Response::HTTP_NO_CONTENT, $headers);
        }
        if (!$request->isMethod('POST')) {
            return new JsonResponse(['error' => 'Send JSON-RPC messages with POST. This server does not open server-to-client streams.'], Response::HTTP_METHOD_NOT_ALLOWED, $headers + ['Allow' => 'POST, DELETE']);
        }

        try {
            $payload = json_decode($request->getContent(), true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return new JsonResponse(McpServer::error(null, McpServer::PARSE_ERROR, 'Parse error: ' . $e->getMessage()), Response::HTTP_BAD_REQUEST, $headers);
        }
        if (!\is_array($payload)) {
            return new JsonResponse(McpServer::error(null, McpServer::INVALID_REQUEST, 'Invalid request: expected a JSON object.'), Response::HTTP_BAD_REQUEST, $headers);
        }

        $key = $this->access->key();
        $batch = array_is_list($payload);
        /** @var list<array<string, mixed>> $messages */
        $messages = $batch ? $payload : [$payload];
        $responses = [];
        foreach ($messages as $message) {
            if (!\is_array($message)) {
                $responses[] = McpServer::error(null, McpServer::INVALID_REQUEST, 'Invalid request: expected a JSON object.');
                continue;
            }
            $response = $this->server->handle($message, $key);
            if (null !== $response) {
                $responses[] = $response;
            }
        }

        if ([] === $responses) {
            return new Response('', Response::HTTP_ACCEPTED, $headers);
        }

        return new JsonResponse($batch ? $responses : $responses[0], Response::HTTP_OK, $headers);
    }
}
