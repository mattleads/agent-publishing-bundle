<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Model;

final class Section
{
    /** @param list<string> $locales */
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        public readonly array $locales,
        public readonly LintSettings $lint = new LintSettings(),
    ) {}

    public function allowsLocale(string $locale): bool
    {
        return \in_array($locale, $this->locales, true);
    }
}
