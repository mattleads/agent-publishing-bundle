<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Mcp\Tool;

use MattLeads\AgentPublishingBundle\Model\ApiKey;
use MattLeads\AgentPublishingBundle\Model\Scope;
use MattLeads\AgentPublishingBundle\Section\SectionRegistry;

final class ListSectionsTool extends AbstractTool
{
    public function __construct(private readonly SectionRegistry $sections) {}

    public function name(): string
    {
        return 'list_sections';
    }

    public function description(): string
    {
        return 'Sections and locales this key may write to, with the lint expectations of each section. Call this first.';
    }

    public function inputSchema(): array
    {
        return self::schema([]);
    }

    public function scope(): Scope
    {
        return Scope::ArticlesRead;
    }

    public function call(array $arguments, ApiKey $key): array
    {
        $items = [];
        foreach ($this->sections->all() as $section) {
            if ($key->allowsSection($section->name)) {
                $items[] = [
                    'name' => $section->name,
                    'path' => $section->path,
                    'locales' => $section->locales,
                    'lint' => ['require_faq' => $section->lint->requireFaq, 'min_words' => $section->lint->minWords, 'min_internal_links' => $section->lint->minInternalLinks],
                ];
            }
        }

        return ['sections' => $items, 'default_locale' => $this->sections->defaultLocale()];
    }
}
