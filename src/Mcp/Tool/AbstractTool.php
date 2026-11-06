<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Mcp\Tool;

use MattLeads\AgentPublishingBundle\Contract\ArticleStorageInterface;
use MattLeads\AgentPublishingBundle\Mcp\McpToolInterface;
use MattLeads\AgentPublishingBundle\Mcp\ToolException;
use MattLeads\AgentPublishingBundle\Model\ApiKey;
use MattLeads\AgentPublishingBundle\Model\ArticleInterface;
use MattLeads\AgentPublishingBundle\Model\ValidationException;

abstract class AbstractTool implements McpToolInterface
{
    /**
     * @param array<string, array<string, mixed>> $properties
     * @param list<string>                        $required
     *
     * @return array<string, mixed>
     */
    protected static function schema(array $properties, array $required = []): array
    {
        $schema = ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
        if ([] !== $required) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /** @param array<string, mixed> $arguments */
    protected static function string(array $arguments, string $name, bool $required = false): ?string
    {
        $value = $arguments[$name] ?? null;
        if (null === $value) {
            if ($required) {
                throw new ToolException(\sprintf('Argument "%s" is required.', $name));
            }

            return null;
        }
        if (!\is_string($value)) {
            throw new ToolException(\sprintf('Argument "%s" must be a string.', $name));
        }

        return $value;
    }

    /** @param array<string, mixed> $arguments */
    protected static function int(array $arguments, string $name, int $default): int
    {
        $value = $arguments[$name] ?? $default;
        if (!\is_int($value)) {
            throw new ToolException(\sprintf('Argument "%s" must be an integer.', $name));
        }

        return $value;
    }

    /** Loads an article the key may see, or fails as "not found" so other sections stay invisible. */
    protected static function article(ArticleStorageInterface $storage, string $id, ApiKey $key): ArticleInterface
    {
        $article = $storage->find($id);
        if (null === $article || !$key->allowsSection($article->getSection())) {
            throw new ToolException(\sprintf('Article "%s" not found.', $id));
        }

        return $article;
    }

    protected static function allowSection(ApiKey $key, ?string $section): void
    {
        if (null !== $section && !$key->allowsSection($section)) {
            throw new ToolException(\sprintf('This key cannot access section "%s".', $section));
        }
    }

    protected static function invalid(ValidationException $e): ToolException
    {
        return new ToolException('Validation failed.', $e->violations);
    }
}
