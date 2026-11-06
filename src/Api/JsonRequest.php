<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Api;

use MattLeads\AgentPublishingBundle\Model\ValidationException;
use Symfony\Component\HttpFoundation\Request;

/**
 * @internal
 */
final class JsonRequest
{
    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function body(Request $request): array
    {
        $content = $request->getContent();
        if ('' === trim($content)) {
            return [];
        }
        try {
            $data = json_decode($content, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ValidationException(['Request body is not valid JSON: ' . $e->getMessage()]);
        }
        if (!\is_array($data) || ([] !== $data && array_is_list($data))) {
            throw new ValidationException(['Request body must be a JSON object.']);
        }

        /* @var array<string, mixed> $data */
        return $data;
    }
}
