<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Tests\Functional;

use Symfony\Component\HttpFoundation\Response;

/** Stands in for a host application's article page. */
final class BlogController
{
    public function show(string $slug): Response
    {
        return 'missing' === $slug ? new Response('nope', 404) : new Response('<h1>' . htmlspecialchars($slug) . '</h1>');
    }
}
