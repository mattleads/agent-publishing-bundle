<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Mcp\Tool;

use MattLeads\AgentPublishingBundle\Api\ArticleNormalizer;
use MattLeads\AgentPublishingBundle\Contract\ArticleStorageInterface;
use MattLeads\AgentPublishingBundle\Mcp\ToolException;
use MattLeads\AgentPublishingBundle\Model\ApiKey;
use MattLeads\AgentPublishingBundle\Model\ArticleInput;
use MattLeads\AgentPublishingBundle\Model\Scope;
use MattLeads\AgentPublishingBundle\Model\ValidationException;
use MattLeads\AgentPublishingBundle\Workflow\ArticleManager;
use MattLeads\AgentPublishingBundle\Workflow\InvalidTransitionException;

final class UpdateDraftTool extends AbstractTool
{
    public function __construct(private readonly ArticleStorageInterface $storage, private readonly ArticleManager $articles, private readonly ArticleNormalizer $normalizer) {}

    public function name(): string
    {
        return 'update_draft';
    }

    public function description(): string
    {
        return 'Change fields of a draft or an article in review. Only the fields you pass change. Published and scheduled articles can be edited only when the site allows it and the key has articles:publish; a slug change then keeps the old URL as a redirect. Archived articles cannot be edited.';
    }

    public function inputSchema(): array
    {
        $fields = CreateDraftTool::fields();
        unset($fields['section'], $fields['locale']);

        return self::schema(['id' => ['type' => 'string']] + $fields, ['id']);
    }

    public function scope(): Scope
    {
        return Scope::ArticlesDraft;
    }

    public function call(array $arguments, ApiKey $key): array
    {
        $article = self::article($this->storage, (string) self::string($arguments, 'id', true), $key);
        unset($arguments['id']);
        if (ArticleManager::isLive($article) && $this->articles->isEditable($article) && !$key->hasScope(Scope::ArticlesPublish)) {
            throw new ToolException(\sprintf('Editing a %s article needs the "%s" scope.', $article->getState()->value, Scope::ArticlesPublish->value));
        }
        try {
            $article = $this->articles->update($article, ArticleInput::fromArray($arguments, partial: true));
        } catch (ValidationException $e) {
            throw self::invalid($e);
        } catch (InvalidTransitionException $e) {
            throw new ToolException($e->getMessage());
        }

        return $this->normalizer->normalize($article);
    }
}
