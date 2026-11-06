<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Api;

use MattLeads\AgentPublishingBundle\Contract\ArticleStorageInterface;
use MattLeads\AgentPublishingBundle\Model\ArticleFilter;
use MattLeads\AgentPublishingBundle\Model\ArticleInput;
use MattLeads\AgentPublishingBundle\Model\ArticleInterface;
use MattLeads\AgentPublishingBundle\Model\ArticleState;
use MattLeads\AgentPublishingBundle\Model\Scope;
use MattLeads\AgentPublishingBundle\Model\ValidationException;
use MattLeads\AgentPublishingBundle\Workflow\ArticleManager;
use MattLeads\AgentPublishingBundle\Workflow\ArticleWorkflow;
use MattLeads\AgentPublishingBundle\Workflow\InvalidTransitionException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ArticleController
{
    public function __construct(
        private readonly ArticleStorageInterface $storage,
        private readonly ArticleManager $articles,
        private readonly ArticleWorkflow $workflow,
        private readonly ArticleNormalizer $normalizer,
        private readonly ApiAccess $access,
    ) {}

    public function list(Request $request): JsonResponse
    {
        $requested = $request->query->get('section');
        if ($denied = $this->access->deny(Scope::ArticlesRead, \is_string($requested) ? $requested : null)) {
            return $denied;
        }
        try {
            $section = $this->access->listingSection(\is_string($requested) ? $requested : null);
            $state = $request->query->get('state');
            if (\is_string($state) && null === ArticleState::tryFrom($state)) {
                throw new ValidationException([\sprintf('Unknown state "%s".', $state)]);
            }
            $filter = new ArticleFilter(
                $section,
                \is_string($l = $request->query->get('locale')) ? $l : null,
                \is_string($state) ? ArticleState::from($state) : null,
                $request->query->getInt('page', 1),
                $request->query->getInt('per_page', 20),
                \is_string($sort = $request->query->get('sort')) ? $sort : 'updated',
            );
        } catch (ValidationException $e) {
            return Problem::json(Response::HTTP_UNPROCESSABLE_ENTITY, 'Invalid query.', ['violations' => $e->violations]);
        }

        $key = $this->access->key();
        $items = array_filter($this->storage->list($filter), static fn(ArticleInterface $a) => null === $key || $key->allowsSection($a->getSection()));

        return new JsonResponse([
            'items' => array_values(array_map($this->normalizer->normalize(...), $items)),
            'total' => $this->storage->count($filter),
            'page' => $filter->page,
            'per_page' => $filter->perPage,
        ]);
    }

    public function create(Request $request): JsonResponse
    {
        try {
            $input = ArticleInput::fromArray(JsonRequest::body($request));
        } catch (ValidationException $e) {
            return $this->invalid($e);
        }
        if ($denied = $this->access->deny(Scope::ArticlesDraft, $input->section)) {
            return $denied;
        }
        try {
            $article = $this->articles->create($input, $this->access->key()?->getName());
        } catch (ValidationException $e) {
            return $this->invalid($e);
        }

        return new JsonResponse($this->normalizer->normalize($article), Response::HTTP_CREATED);
    }

    public function get(string $id): JsonResponse
    {
        $article = $this->storage->find($id);
        if (null === $article) {
            return Problem::json(Response::HTTP_NOT_FOUND, 'Article not found.');
        }
        if ($denied = $this->access->denyForArticle(Scope::ArticlesRead, $article)) {
            return $denied;
        }

        return new JsonResponse($this->normalizer->normalize($article));
    }

    public function update(string $id, Request $request): JsonResponse
    {
        $article = $this->storage->find($id);
        if (null === $article) {
            return Problem::json(Response::HTTP_NOT_FOUND, 'Article not found.');
        }
        if ($denied = $this->access->denyForArticle(ArticleManager::isLive($article) && $this->articles->isEditable($article) ? Scope::ArticlesPublish : Scope::ArticlesDraft, $article)) {
            return $denied;
        }
        try {
            $article = $this->articles->update($article, ArticleInput::fromArray(JsonRequest::body($request), partial: true));
        } catch (ValidationException $e) {
            return $this->invalid($e);
        } catch (InvalidTransitionException $e) {
            return Problem::json(Response::HTTP_CONFLICT, $e->getMessage());
        }

        return new JsonResponse($this->normalizer->normalize($article));
    }

    public function transition(string $id, string $transition, Request $request): JsonResponse
    {
        $article = $this->storage->find($id);
        if (null === $article) {
            return Problem::json(Response::HTTP_NOT_FOUND, 'Article not found.');
        }
        try {
            $scope = ArticleWorkflow::scopeFor($transition);
            $body = JsonRequest::body($request);
        } catch (InvalidTransitionException) {
            return Problem::json(Response::HTTP_NOT_FOUND, \sprintf('Unknown transition "%s".', $transition));
        } catch (ValidationException $e) {
            return $this->invalid($e);
        }
        if ($denied = $this->access->denyForArticle($scope, $article)) {
            return $denied;
        }

        $note = $body['reason'] ?? null;
        if (null !== $note && !\is_string($note)) {
            return $this->invalid(new ValidationException(['Field "reason" must be a string.']));
        }
        $publishAt = null;
        if (isset($body['publish_at'])) {
            try {
                $publishAt = \is_string($body['publish_at']) ? new \DateTimeImmutable($body['publish_at']) : throw new \Exception();
            } catch (\Exception) {
                return $this->invalid(new ValidationException(['Field "publish_at" must be an ISO 8601 date-time.']));
            }
        }

        try {
            $article = $this->workflow->apply($article, $transition, $note, $publishAt, $this->access->key()?->getName());
        } catch (InvalidTransitionException $e) {
            return Problem::json(Response::HTTP_CONFLICT, $e->getMessage());
        }

        return new JsonResponse($this->normalizer->normalize($article));
    }

    private function invalid(ValidationException $e): JsonResponse
    {
        return Problem::json(Response::HTTP_UNPROCESSABLE_ENTITY, 'Validation failed.', ['violations' => $e->violations]);
    }
}
