<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Tests\Unit\Lint;

use MattLeads\AgentPublishingBundle\Lint\Content\ContentInspector;
use MattLeads\AgentPublishingBundle\Lint\LintLevel;
use MattLeads\AgentPublishingBundle\Lint\LintSubject;
use MattLeads\AgentPublishingBundle\Lint\Rule\MetaDescriptionRule;
use MattLeads\AgentPublishingBundle\Model\ArticleInput;
use MattLeads\AgentPublishingBundle\Model\LintSettings;
use MattLeads\AgentPublishingBundle\Model\Section;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MetaDescriptionRuleTest extends TestCase
{
    /** @return iterable<string, array{array<string, string>, LintLevel}> */
    public static function cases(): iterable
    {
        yield 'neither' => [[], LintLevel::Warn];
        yield 'summary stands in' => [['summary' => 'A summary.'], LintLevel::Pass];
        yield 'good length' => [['meta_description' => str_repeat('a', 120)], LintLevel::Pass];
        yield 'too short' => [['meta_description' => 'Short.'], LintLevel::Warn];
        yield 'too long' => [['meta_description' => str_repeat('a', 161)], LintLevel::Warn];
    }

    /** @param array<string, string> $fields */
    #[DataProvider('cases')]
    public function testLevels(array $fields, LintLevel $expected): void
    {
        $input = ArticleInput::fromArray($fields + ['section' => 'blog', 'locale' => 'en', 'title' => 'T', 'body' => 'B']);
        $subject = LintSubject::fromInput($input, new Section('blog', '/blog', ['en'], new LintSettings()), new ContentInspector());

        self::assertSame($expected, (new MetaDescriptionRule())->check($subject)->level);
    }
}
