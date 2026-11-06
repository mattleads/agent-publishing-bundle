<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Api;

use MattLeads\AgentPublishingBundle\Contract\ArticleStorageInterface;
use MattLeads\AgentPublishingBundle\Lint\Content\ContentInspector;
use MattLeads\AgentPublishingBundle\Lint\Linter;
use MattLeads\AgentPublishingBundle\Lint\LintSubject;
use MattLeads\AgentPublishingBundle\Model\ArticleInput;
use MattLeads\AgentPublishingBundle\Model\Scope;
use MattLeads\AgentPublishingBundle\Model\ValidationException;
use MattLeads\AgentPublishingBundle\Section\SectionRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class LintController
{
    public function __construct(
        private readonly Linter $linter,
        private readonly ContentInspector $inspector,
        private readonly ArticleStorageInterface $storage,
        private readonly SectionRegistry $sections,
        private readonly ApiAccess $access,
    ) {}

    /** Lint a stored article. No state change. */
    public function article(string $id): JsonResponse
    {
        $article = $this->storage->find($id);
        if (null === $article) {
            return Problem::json(Response::HTTP_NOT_FOUND, 'Article not found.');
        }
        if ($denied = $this->access->denyForArticle(Scope::ArticlesRead, $article)) {
            return $denied;
        }
        $section = $this->sections->get($article->getSection());
        if (null === $section) {
            return Problem::json(Response::HTTP_CONFLICT, \sprintf('Section "%s" is no longer configured.', $article->getSection()));
        }

        return new JsonResponse(['article_id' => $article->getId()] + $this->linter->lint(LintSubject::fromArticle($article, $section, $this->inspector))->toArray());
    }

    /** Lint a payload that has not been saved, so an agent can iterate before creating a draft. */
    public function payload(Request $request): JsonResponse
    {
        try {
            $input = ArticleInput::fromArray(JsonRequest::body($request));
        } catch (ValidationException $e) {
            return Problem::json(Response::HTTP_UNPROCESSABLE_ENTITY, 'Validation failed.', ['violations' => $e->violations]);
        }
        if ($denied = $this->access->deny(Scope::ArticlesRead, $input->section)) {
            return $denied;
        }
        $section = $this->sections->get((string) $input->section);
        if (null === $section) {
            return Problem::json(Response::HTTP_UNPROCESSABLE_ENTITY, 'Validation failed.', ['violations' => [\sprintf('Unknown section "%s".', $input->section)]]);
        }
        if (!$section->allowsLocale((string) $input->locale)) {
            return Problem::json(Response::HTTP_UNPROCESSABLE_ENTITY, 'Validation failed.', ['violations' => [\sprintf('Section "%s" does not accept locale "%s".', $section->name, $input->locale)]]);
        }

        return new JsonResponse($this->linter->lint(LintSubject::fromInput($input, $section, $this->inspector))->toArray());
    }
}
