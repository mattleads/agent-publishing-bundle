<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Tests\Unit\Security;

use MattLeads\AgentPublishingBundle\Model\Scope;
use MattLeads\AgentPublishingBundle\Security\ApiKeyManager;
use MattLeads\AgentPublishingBundle\Security\ApiKeyUser;
use MattLeads\AgentPublishingBundle\Storage\InMemoryApiKeyStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ApiKeyManagerTest extends TestCase
{
    public function testEmptyPepperIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        (new ApiKeyManager(new InMemoryApiKeyStorage(), ''))->issue('x', []);
    }

    public function testIssueResolveAndRevoke(): void
    {
        $storage = new InMemoryApiKeyStorage();
        $clock = new MockClock('2026-11-06 10:00:00', 'UTC');
        $manager = new ApiKeyManager($storage, 'pepper', $clock);

        $issued = $manager->issue('marketcells', [Scope::ArticlesDraft, Scope::ArticlesDraft, Scope::StatsRead], ['blog']);
        self::assertStringStartsWith('apb_', $issued['secret']);
        self::assertGreaterThan(40, \strlen($issued['secret']));
        self::assertSame([Scope::ArticlesDraft, Scope::StatsRead], $issued['key']->getScopes());
        self::assertNotSame($issued['secret'], $issued['key']->getHash(), 'the secret is never stored');
        self::assertNull($issued['key']->getLastUsedAt());

        $clock->modify('+5 minutes');
        $key = $manager->resolve($issued['secret']);
        self::assertNotNull($key);
        self::assertSame('marketcells', $key->getName());
        self::assertEquals($clock->now(), $key->getLastUsedAt());
        self::assertTrue($key->allowsSection('blog'));
        self::assertFalse($key->allowsSection('docs'));
        self::assertSame(['ROLE_AGENT_PUBLISHING', 'ROLE_AGENT_PUBLISHING_ARTICLES_DRAFT', 'ROLE_AGENT_PUBLISHING_STATS_READ'], (new ApiKeyUser($key))->getRoles());

        self::assertNull($manager->resolve('apb_' . str_repeat('x', 43)));
        self::assertNull($manager->resolve('not-a-key'));
        self::assertNull((new ApiKeyManager($storage, 'other-pepper'))->resolve($issued['secret']), 'a different pepper does not match');

        $manager->revoke($key);
        self::assertNull($manager->resolve($issued['secret']));
        self::assertTrue($key->isRevoked());
    }
}
