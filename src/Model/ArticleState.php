<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Model;

enum ArticleState: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Archived = 'archived';

    public function isEditable(): bool
    {
        return self::Draft === $this || self::InReview === $this;
    }
}
