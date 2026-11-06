<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Model;

enum Scope: string
{
    case ArticlesRead = 'articles:read';
    case ArticlesDraft = 'articles:draft';
    case ArticlesApprove = 'articles:approve';
    case ArticlesPublish = 'articles:publish';
    case StatsRead = 'stats:read';
    case KeysManage = 'keys:manage';

    public function role(): string
    {
        return 'ROLE_AGENT_PUBLISHING_' . strtoupper(str_replace(':', '_', $this->value));
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn(self $scope) => $scope->value, self::cases());
    }
}
