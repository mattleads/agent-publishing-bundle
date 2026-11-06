<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Api;

use Symfony\Component\HttpFoundation\JsonResponse;

final class OpenApiController
{
    public function __construct(private readonly string $prefix) {}

    public function document(): JsonResponse
    {
        return new JsonResponse(OpenApiDocument::build($this->prefix), 200, ['Cache-Control' => 'public, max-age=300']);
    }
}
