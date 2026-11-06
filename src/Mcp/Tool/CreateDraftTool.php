<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Mcp\Tool;

use MattLeads\AgentPublishingBundle\Api\ArticleNormalizer;
use MattLeads\AgentPublishingBundle\Model\ApiKey;
use MattLeads\AgentPublishingBundle\Model\ArticleInput;
use MattLeads\AgentPublishingBundle\Model\Scope;
use MattLeads\AgentPublishingBundle\Model\ValidationException;
use MattLeads\AgentPublishingBundle\Workflow\ArticleManager;

final class CreateDraftTool extends AbstractTool
{
    public function __construct(private readonly ArticleManager $articles, private readonly ArticleNormalizer $normalizer) {}

    public function name(): string
    {
        return 'create_draft';
    }

    public function description(): string
    {
        return 'Create a draft article. Run lint_draft on the same fields first and fix every fail and warn; then create, then submit_article.';
    }

    public function inputSchema(): array
    {
        return self::schema(self::fields(), ['section', 'locale', 'title', 'body']);
    }

    /** @return array<string, array<string, mixed>> */
    public static function fields(): array
    {
        return [
            'section' => ['type' => 'string'],
            'locale' => ['type' => 'string'],
            'title' => ['type' => 'string', 'maxLength' => 200],
            'body' => ['type' => 'string', 'description' => 'Markdown unless format is "html".'],
            'summary' => ['type' => 'string', 'maxLength' => 500, 'description' => 'Meta description, 70-160 characters.'],
            'slug' => ['type' => 'string', 'description' => 'Derived from the title when omitted.'],
            'format' => ['type' => 'string', 'enum' => ArticleInput::FORMATS],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => ArticleInput::MAX_TAGS],
            'translation_group' => ['type' => 'string', 'description' => 'Shared by the translations of one article.'],
        ];
    }

    public function scope(): Scope
    {
        return Scope::ArticlesDraft;
    }

    public function call(array $arguments, ApiKey $key): array
    {
        try {
            $input = ArticleInput::fromArray($arguments);
            self::allowSection($key, $input->section);
            $article = $this->articles->create($input, $key->getName());
        } catch (ValidationException $e) {
            throw self::invalid($e);
        }

        return $this->normalizer->normalize($article);
    }
}
