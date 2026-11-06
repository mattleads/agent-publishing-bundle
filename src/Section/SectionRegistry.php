<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Section;

use MattLeads\AgentPublishingBundle\Model\LintSettings;
use MattLeads\AgentPublishingBundle\Model\Section;

final class SectionRegistry
{
    /** @var array<string, Section> */
    private array $sections = [];

    /**
     * @param array<string, array{path: string, locales: list<string>, lint?: array{require_faq: bool, min_words: int, min_internal_links: int}}> $sections
     * @param list<string>                                                                                                                        $locales
     */
    public function __construct(array $sections, private readonly array $locales, private readonly string $defaultLocale)
    {
        foreach ($sections as $name => $definition) {
            $sectionLocales = [] === $definition['locales'] ? $locales : $definition['locales'];
            foreach ($sectionLocales as $locale) {
                if (!\in_array($locale, $locales, true)) {
                    throw new \InvalidArgumentException(\sprintf('Section "%s" lists locale "%s", which is not in mattleads_agent_publishing.locales.', $name, $locale));
                }
            }
            $lint = $definition['lint'] ?? ['require_faq' => false, 'min_words' => 300, 'min_internal_links' => 1];
            $this->sections[$name] = new Section($name, $definition['path'], array_values($sectionLocales), new LintSettings($lint['require_faq'], $lint['min_words'], $lint['min_internal_links']));
        }
    }

    public function get(string $name): ?Section
    {
        return $this->sections[$name] ?? null;
    }

    /** @return list<Section> */
    public function all(): array
    {
        return array_values($this->sections);
    }

    /** @return list<string> */
    public function locales(): array
    {
        return $this->locales;
    }

    public function defaultLocale(): string
    {
        return $this->defaultLocale;
    }
}
