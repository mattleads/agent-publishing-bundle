<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Mcp\Tool;

use MattLeads\AgentPublishingBundle\Contract\ArticleStorageInterface;
use MattLeads\AgentPublishingBundle\Lint\Content\ContentInspector;
use MattLeads\AgentPublishingBundle\Lint\Linter;
use MattLeads\AgentPublishingBundle\Lint\LintSubject;
use MattLeads\AgentPublishingBundle\Mcp\ToolException;
use MattLeads\AgentPublishingBundle\Model\ApiKey;
use MattLeads\AgentPublishingBundle\Model\ArticleInput;
use MattLeads\AgentPublishingBundle\Model\Scope;
use MattLeads\AgentPublishingBundle\Model\ValidationException;
use MattLeads\AgentPublishingBundle\Section\SectionRegistry;

/** Two instances: lint_draft (fields you have not saved) and lint_article (a stored article). */
final class LintTool extends AbstractTool
{
    public function __construct(
        private readonly bool $stored,
        private readonly Linter $linter,
        private readonly ContentInspector $inspector,
        private readonly ArticleStorageInterface $storage,
        private readonly SectionRegistry $sections,
    ) {}

    public function name(): string
    {
        return $this->stored ? 'lint_article' : 'lint_draft';
    }

    public function description(): string
    {
        return $this->stored
            ? 'Check a stored article against the SEO and answer-engine rules. Returns pass, warn or fail per rule with a hint. Nothing changes.'
            : 'Check article fields before creating anything. Same rules as lint_article; loop until status is "pass", then create_draft with the same fields.';
    }

    public function inputSchema(): array
    {
        return $this->stored
            ? self::schema(['id' => ['type' => 'string']], ['id'])
            : self::schema(CreateDraftTool::fields(), ['section', 'locale', 'title', 'body']);
    }

    public function scope(): Scope
    {
        return Scope::ArticlesRead;
    }

    public function call(array $arguments, ApiKey $key): array
    {
        if ($this->stored) {
            $article = self::article($this->storage, (string) self::string($arguments, 'id', true), $key);
            $section = $this->sections->get($article->getSection()) ?? throw new ToolException(\sprintf('Section "%s" is no longer configured.', $article->getSection()));

            return ['article_id' => $article->getId()] + $this->linter->lint(LintSubject::fromArticle($article, $section, $this->inspector))->toArray();
        }

        try {
            $input = ArticleInput::fromArray($arguments);
        } catch (ValidationException $e) {
            throw self::invalid($e);
        }
        self::allowSection($key, $input->section);
        $section = $this->sections->get((string) $input->section) ?? throw new ToolException(\sprintf('Unknown section "%s".', $input->section));
        if (!$section->allowsLocale((string) $input->locale)) {
            throw new ToolException(\sprintf('Section "%s" does not accept locale "%s".', $section->name, $input->locale));
        }

        return $this->linter->lint(LintSubject::fromInput($input, $section, $this->inspector))->toArray();
    }
}
