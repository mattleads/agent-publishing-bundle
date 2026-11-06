<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Tests\Unit\Lint;

use MattLeads\AgentPublishingBundle\Lint\Content\ContentInspector;
use MattLeads\AgentPublishingBundle\Lint\Linter;
use MattLeads\AgentPublishingBundle\Lint\LintLevel;
use MattLeads\AgentPublishingBundle\Lint\LintSubject;
use MattLeads\AgentPublishingBundle\Lint\Rule\FaqRule;
use MattLeads\AgentPublishingBundle\Lint\Rule\HeadingsRule;
use MattLeads\AgentPublishingBundle\Lint\Rule\ImagesAltRule;
use MattLeads\AgentPublishingBundle\Lint\Rule\LinksRule;
use MattLeads\AgentPublishingBundle\Lint\Rule\OpeningParagraphRule;
use MattLeads\AgentPublishingBundle\Lint\Rule\ReadabilityRule;
use MattLeads\AgentPublishingBundle\Lint\Rule\SlugRule;
use MattLeads\AgentPublishingBundle\Lint\Rule\SummaryRule;
use MattLeads\AgentPublishingBundle\Lint\Rule\TagsRule;
use MattLeads\AgentPublishingBundle\Lint\Rule\TitleLengthRule;
use MattLeads\AgentPublishingBundle\Lint\Rule\WordCountRule;
use MattLeads\AgentPublishingBundle\Model\ArticleInput;
use MattLeads\AgentPublishingBundle\Model\LintSettings;
use MattLeads\AgentPublishingBundle\Model\Section;
use MattLeads\AgentPublishingBundle\Storage\InMemoryArticleStorage;
use PHPUnit\Framework\TestCase;

final class LinterTest extends TestCase
{
    private Linter $linter;
    private InMemoryArticleStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new InMemoryArticleStorage();
        $this->linter = new Linter([
            new TitleLengthRule(), new SummaryRule(), new OpeningParagraphRule(), new HeadingsRule(), new WordCountRule(),
            new ReadabilityRule(), new ImagesAltRule(), new TagsRule(), new FaqRule(), new LinksRule('example.com'), new SlugRule($this->storage),
        ]);
    }

    public function testGoodArticlePasses(): void
    {
        $body = "Symfony Messenger retries a failed message with a growing delay, and the retry counter lives in a stamp. This paragraph answers the title directly so an answer engine can quote it, and it explains why the counter matters for your failure transport.\n\n"
            . "## How retries work\n\n" . str_repeat('The worker reads the stamp and decides whether to retry or to move the message. ', 12) . "See [the retry guide](/docs/retries).\n\n"
            . "## Where it breaks\n\n" . str_repeat('A serializer that throws loses the stamp, and the worker starts again from the first retry. ', 12) . "\n\n"
            . "![Retry flow](/img/retry.png)\n\n## FAQ\n\n### Does this affect AMQP?\n\nYes, every transport wraps the failure the same way, so the counter is lost on all of them.";
        $report = $this->linter->lint($this->subject([
            'title' => 'Symfony Messenger Retries: Why a Throwing Serializer Loops',
            'summary' => 'How Messenger counts retries, why a serializer that throws from decode() restarts the counter, and the one-line fix.',
            'body' => $body, 'slug' => 'symfony-messenger-retries-throwing-serializer', 'tags' => ['symfony', 'messenger'],
        ], new LintSettings(requireFaq: true)));

        $levels = array_combine(array_map(static fn($c) => $c->rule, $report->checks), array_map(static fn($c) => $c->level->value, $report->checks));
        self::assertSame(LintLevel::Pass, $report->status(), (string) json_encode($report->toArray(), \JSON_PRETTY_PRINT));
        self::assertSame(['title_length', 'summary', 'opening_paragraph', 'headings', 'word_count', 'readability', 'images_alt', 'tags', 'faq', 'links', 'slug'], array_keys($levels));
    }

    public function testWeakArticleReportsEachProblem(): void
    {
        $this->storage->create(ArticleInput::fromArray(['section' => 'blog', 'locale' => 'en', 'title' => 'Taken', 'body' => 'x']), 'taken', null, new \DateTimeImmutable());

        $body = "# Shouting title\n\n## Short\n\nTiny. ![](/no-alt.png) [click here](https://elsewhere.test)\n\n#### Skipped level";
        $report = $this->linter->lint($this->subject([
            'title' => 'Hi', 'summary' => null, 'body' => $body, 'slug' => 'taken', 'tags' => [],
        ], new LintSettings(requireFaq: true)));

        $byRule = [];
        foreach ($report->checks as $check) {
            $byRule[$check->rule] = $check;
        }
        self::assertSame(LintLevel::Fail, $report->status());
        self::assertSame(LintLevel::Warn, $byRule['title_length']->level);
        self::assertSame(LintLevel::Fail, $byRule['summary']->level);
        self::assertSame(LintLevel::Warn, $byRule['opening_paragraph']->level);
        self::assertStringContainsString('does not start with a paragraph', $byRule['opening_paragraph']->message);
        self::assertSame(LintLevel::Warn, $byRule['headings']->level);
        self::assertStringContainsString('H1', $byRule['headings']->message);
        self::assertSame(LintLevel::Warn, $byRule['word_count']->level);
        self::assertArrayNotHasKey('readability', $byRule, 'skipped under 100 words');
        self::assertSame(LintLevel::Fail, $byRule['images_alt']->level);
        self::assertSame(LintLevel::Warn, $byRule['tags']->level);
        self::assertSame(LintLevel::Fail, $byRule['faq']->level);
        self::assertSame(LintLevel::Warn, $byRule['links']->level);
        self::assertStringContainsString('click here', $byRule['links']->message);
        self::assertSame(LintLevel::Fail, $byRule['slug']->level);
        self::assertNotNull($byRule['slug']->hint);
        self::assertSame(['pass' => 0, 'warn' => 6, 'fail' => 4], $report->toArray()['summary']);
    }

    public function testNonEnglishSkipsReadabilityAndHtmlIsSupported(): void
    {
        $body = '<p>' . str_repeat('Ein Absatz mit genug Wörtern, der die Frage im Titel beantwortet und den Leser direkt anspricht. ', 6) . '</p><h2>Abschnitt</h2><p>' . str_repeat('Mehr Text. ', 150) . '</p>';
        $report = $this->linter->lint($this->subject(['title' => 'Wie Symfony Messenger Wiederholungen zählt', 'summary' => str_repeat('Zusammenfassung ', 6), 'body' => $body, 'format' => 'html', 'tags' => ['symfony'], 'locale' => 'de']));
        self::assertNotContains('readability', array_map(static fn($c) => $c->rule, $report->checks));
        self::assertSame(LintLevel::Warn, $report->status(), 'only the missing internal link warns');
    }

    /** @param array<string, mixed> $fields */
    private function subject(array $fields, ?LintSettings $lint = null): LintSubject
    {
        $input = ArticleInput::fromArray($fields + ['section' => 'blog', 'locale' => 'en', 'title' => 'T', 'body' => 'B']);
        $section = new Section('blog', '/blog', ['en', 'de'], $lint ?? new LintSettings());

        return LintSubject::fromInput($input, $section, new ContentInspector());
    }
}
