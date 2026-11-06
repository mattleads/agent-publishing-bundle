<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Api;

use MattLeads\AgentPublishingBundle\Model\Scope;
use MattLeads\AgentPublishingBundle\Section\SectionRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;

final class SectionController
{
    public function __construct(private readonly SectionRegistry $sections, private readonly ApiAccess $access) {}

    public function list(): JsonResponse
    {
        if ($denied = $this->access->deny(Scope::ArticlesRead)) {
            return $denied;
        }
        $key = $this->access->key();

        return new JsonResponse([
            'items' => array_values(array_map(
                static fn($s) => ['name' => $s->name, 'path' => $s->path, 'locales' => $s->locales],
                array_filter($this->sections->all(), static fn($s) => null === $key || $key->allowsSection($s->name)),
            )),
            'locales' => $this->sections->locales(),
            'default_locale' => $this->sections->defaultLocale(),
        ]);
    }
}
