<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Api;

use Symfony\Component\HttpFoundation\JsonResponse;

final class OpenApiController
{
    public function __construct(private readonly string $prefix, private readonly bool $keyManagement = true) {}

    public function document(): JsonResponse
    {
        return new JsonResponse(OpenApiDocument::build($this->prefix, $this->keyManagement), 200, ['Cache-Control' => 'public, max-age=300']);
    }
}
