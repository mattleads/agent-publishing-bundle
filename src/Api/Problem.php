<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Api;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * @internal
 */
final class Problem
{
    /** @param array<string, mixed> $extra */
    public static function json(int $status, string $error, array $extra = []): JsonResponse
    {
        return new JsonResponse(['error' => $error] + $extra, $status);
    }
}
