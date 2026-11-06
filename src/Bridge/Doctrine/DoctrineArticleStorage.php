<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Bridge\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use MattLeads\AgentPublishingBundle\Contract\ArticleStorageInterface;
use MattLeads\AgentPublishingBundle\Model\Article;
use MattLeads\AgentPublishingBundle\Model\ArticleFilter;
use MattLeads\AgentPublishingBundle\Model\ArticleInput;
use MattLeads\AgentPublishingBundle\Model\ArticleInterface;
use MattLeads\AgentPublishingBundle\Model\ArticleState;
use Symfony\Component\Uid\Ulid;

final class DoctrineArticleStorage implements ArticleStorageInterface
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function find(string $id): ?ArticleInterface
    {
        return $this->em->find(Article::class, $id);
    }

    public function list(ArticleFilter $filter): array
    {
        /** @var list<Article> $result */
        $result = $this->filtered($filter)
            ->orderBy('a.updatedAt', 'DESC')->addOrderBy('a.id', 'DESC')
            ->setFirstResult($filter->offset())->setMaxResults($filter->perPage)
            ->getQuery()->getResult();

        return $result;
    }

    public function count(ArticleFilter $filter): int
    {
        return (int) $this->filtered($filter)->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();
    }

    public function slugExists(string $section, string $locale, string $slug, ?string $exceptId = null): bool
    {
        $qb = $this->em->createQueryBuilder()->select('COUNT(a.id)')->from(Article::class, 'a')
            ->andWhere('a.section = :section')->setParameter('section', $section)
            ->andWhere('a.locale = :locale')->setParameter('locale', $locale)
            ->andWhere('a.slug = :slug')->setParameter('slug', $slug);
        if (null !== $exceptId) {
            $qb->andWhere('a.id <> :id')->setParameter('id', $exceptId);
        }

        return 0 < (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function findBySlug(string $section, string $locale, string $slug): ?ArticleInterface
    {
        return $this->em->getRepository(Article::class)->findOneBy(['section' => $section, 'locale' => $locale, 'slug' => $slug]);
    }

    public function create(ArticleInput $input, string $slug, ?string $createdBy, \DateTimeImmutable $now): ArticleInterface
    {
        $article = new Article((string) new Ulid(), (string) $input->section, (string) $input->locale, (string) $input->title, $slug, (string) $input->body, $input->format ?? 'markdown', $now, $createdBy);
        $article->apply($input, null, $now);
        $this->em->persist($article);
        $this->em->flush();

        return $article;
    }

    public function update(ArticleInterface $article, ArticleInput $input, ?string $slug, \DateTimeImmutable $now): ArticleInterface
    {
        $entity = $this->entity($article);
        $entity->apply($input, $slug, $now);
        $this->em->flush();

        return $entity;
    }

    public function transition(ArticleInterface $article, ArticleState $to, \DateTimeImmutable $now, ?string $note = null, ?\DateTimeImmutable $publishAt = null): ArticleInterface
    {
        $entity = $this->entity($article);
        $entity->transitionTo($to, $now, $note, $publishAt);
        $this->em->flush();

        return $entity;
    }

    private function entity(ArticleInterface $article): Article
    {
        if ($article instanceof Article) {
            return $article;
        }

        return $this->em->find(Article::class, $article->getId()) ?? throw new \LogicException(\sprintf('Article "%s" not found.', $article->getId()));
    }

    private function filtered(ArticleFilter $filter): QueryBuilder
    {
        $qb = $this->em->createQueryBuilder()->select('a')->from(Article::class, 'a');
        if (null !== $filter->section) {
            $qb->andWhere('a.section = :section')->setParameter('section', $filter->section);
        }
        if (null !== $filter->locale) {
            $qb->andWhere('a.locale = :locale')->setParameter('locale', $filter->locale);
        }
        if (null !== $filter->state) {
            $qb->andWhere('a.state = :state')->setParameter('state', $filter->state->value);
        }

        return $qb;
    }
}
