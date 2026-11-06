# Agent Publishing Bundle

[![CI](https://github.com/mattleads/agent-publishing-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/mattleads/agent-publishing-bundle/actions/workflows/ci.yml)

A publishing API for Symfony sites. Install it and AI agents, marketing platforms and your own scripts can draft, submit, approve and publish articles through scoped API keys, in the sections and languages you declare in YAML. No frontend ships with this bundle: approval happens through the API, and your site keeps rendering articles the way it already does.

Status: early development. See [DESIGN.md](DESIGN.md) for the full plan (analytics, SEO/AEO lint, MCP server).

## Requirements

* PHP 8.2+, Symfony 6.4 / 7.x / 8.x
* Optional: Doctrine ORM for the bundled storage. Without it, implement `ArticleStorageInterface` and `ApiKeyStorageInterface` yourself.

## Installation

```bash
composer require mattleads/agent-publishing-bundle
```

```yaml
# config/packages/mattleads_agent_publishing.yaml
mattleads_agent_publishing:
    storage: doctrine             # doctrine | memory | custom
    locales: [en, de]
    default_locale: en
    sections:
        blog: { path: /blog }                 # all locales
        docs: { path: /docs, locales: [en] }
    api:
        prefix: /api/publisher/v1
```

```yaml
# config/routes/mattleads_agent_publishing.yaml
mattleads_agent_publishing:
    resource: '@MattLeadsAgentPublishingBundle/config/routes.php'
```

```yaml
# config/packages/security.yaml
security:
    firewalls:
        agent_publishing:
            pattern: ^/api/publisher
            stateless: true
            provider: app_users          # any provider; API keys carry their own identity
            entry_point: MattLeads\AgentPublishingBundle\Security\ApiKeyAuthenticator
            custom_authenticators:
                - MattLeads\AgentPublishingBundle\Security\ApiKeyAuthenticator
    access_control:
        - { path: ^/api/publisher/v1/openapi\.json$, roles: PUBLIC_ACCESS }
        - { path: ^/api/publisher, roles: ROLE_AGENT_PUBLISHING }
```

The entry point answers requests without a key with a JSON 401. Each scope also maps to a role (`articles:draft` to `ROLE_AGENT_PUBLISHING_ARTICLES_DRAFT`) if you prefer `access_control` rules per path.

With `storage: doctrine`, run a migration: the bundle maps `agent_publishing_article`, `agent_publishing_api_key`, `agent_publishing_view_stat` and `agent_publishing_referrer_stat`.

## Issue a key

```bash
php bin/console agent-publishing:api-key:create marketcells --scope=articles:draft --scope=articles:read --section=blog
```

The secret is printed once. Scopes:

| Scope | Allows |
|---|---|
| `articles:read` | list and read articles in any state |
| `articles:draft` | create and update drafts, submit for review |
| `articles:approve` | approve or reject articles in review |
| `articles:publish` | publish directly or on a schedule, archive |
| `stats:read` | view counts and referrers |
| `keys:manage` | issue and revoke keys (planned) |

A key with no `--section` can write to every section.

## Use the API

The OpenAPI 3.1 document is served at `GET /api/publisher/v1/openapi.json`. Point a client generator or an agent at it; every operation names the scope it needs in `x-scope`.

```
GET    /api/publisher/v1/openapi.json
GET    /api/publisher/v1/articles?section=blog&locale=en&state=draft&page=1&per_page=20
POST   /api/publisher/v1/articles                { section, locale, title, body, format?, slug?, summary?, tags?, translation_group? }
GET    /api/publisher/v1/articles/{id}
PATCH  /api/publisher/v1/articles/{id}
POST   /api/publisher/v1/articles/{id}/submit
POST   /api/publisher/v1/articles/{id}/approve
POST   /api/publisher/v1/articles/{id}/reject    { reason }
POST   /api/publisher/v1/articles/{id}/publish   { publish_at? }
POST   /api/publisher/v1/articles/{id}/archive
POST   /api/publisher/v1/articles/{id}/lint
POST   /api/publisher/v1/lint                     { same body as create; nothing is stored }
GET    /api/publisher/v1/articles/{id}/stats?from=&to=
GET    /api/publisher/v1/stats/summary?section=&from=&to=
GET    /api/publisher/v1/stats/referrers?section=&from=&to=&limit=
```

Send the key as `Authorization: Bearer apb_...`. Errors are JSON with `error` and, for validation, `violations`.

## Workflow

`draft` → `in_review` (submit) → `published` (approve) or back to `draft` (reject). A key with `articles:publish` can publish from `draft` or `in_review` directly, or schedule with `publish_at`, which moves the article to `scheduled`. `archive` ends the life of a published or scheduled article. Every transition dispatches `MattLeads\AgentPublishingBundle\Workflow\ArticleTransitioned`.

### Scheduled publishing

Something has to publish scheduled articles when their time comes. Pick one:

```bash
# cron, every minute
* * * * * php /path/to/app/bin/console agent-publishing:publish-scheduled
```

```yaml
# or Symfony Scheduler (composer require symfony/scheduler, then run messenger:consume scheduler_default)
mattleads_agent_publishing:
    scheduler: { enabled: true, frequency: '1 minute', schedule: default }
```

The transition is recorded with actor `scheduler` in the `ArticleTransitioned` event.

## Lint: let the agent fix the article before a human sees it

`POST /articles/{id}/lint` checks a stored article, `POST /lint` checks a payload you have not created yet. Both return one entry per rule with `pass`, `warn` or `fail`, a message saying what was found and a hint saying how to fix it. An agent loops on the report until the status is `pass`, then submits.

| Rule | Checks |
|---|---|
| `title_length` | 30-60 characters; fail above 70 |
| `summary` | present, 70-160 characters, not a copy of the title (it is the meta description) |
| `opening_paragraph` | the body starts with a 40-120 word paragraph that uses the title's terms, which is what answer engines quote |
| `headings` | no H1 in the body, no skipped levels, at least two H2 above 500 words |
| `word_count` | at least `min_words` for the section |
| `readability` | Flesch reading ease for English, warns below 30 |
| `images_alt` | every image has alt text |
| `links` | descriptive link text, at least `min_internal_links` internal links |
| `tags` | between 1 and 8 |
| `slug` | unique in the section and locale, under 75 characters, shares a term with the title |
| `faq` | a FAQ heading, only for sections with `require_faq: true` |

Per-section expectations live in configuration:

```yaml
mattleads_agent_publishing:
    sections:
        docs: { path: /docs, lint: { require_faq: true, min_words: 500, min_internal_links: 2 } }
```

Add your own rule by implementing `LintRuleInterface` and tagging the service `mattleads_agent_publishing.lint_rule`.

## Analytics

The bundle counts views per article and per referrer, by day. It stores counts only: no cookies, no IP addresses, no user agents, no raw URLs. Each view is bucketed into a kind:

| Kind | Meaning |
|---|---|
| `direct` | no referrer |
| `internal` | a link from your own site |
| `search` | Google, Bing, DuckDuckGo and other engines |
| `social` | X, LinkedIn, Reddit, Hacker News, Telegram and friends |
| `ai_referral` | a human sent by ChatGPT, Perplexity, Claude, Gemini or another assistant |
| `ai_crawler` | GPTBot, ClaudeBot, PerplexityBot, Google-Extended and other AI crawlers |
| `bot` | everything else that is not a browser |
| `referral` | any other site |

`views` counts human kinds. `crawler_views` counts `ai_crawler` and `bot`, so you can see which articles AI systems read without inflating the human numbers.

### Tell the bundle which page is an article

Add a route default to the route that renders an article. The listener runs after the response is sent, finds the published article by section, locale and slug, and counts the view:

```php
#[Route('/blog/{slug}', name: 'blog_show', defaults: ['_agent_publishing_section' => 'blog'])]
```

The slug comes from the `slug` attribute and the locale from `_locale`, falling back to `default_locale`. Both names are configurable under `analytics`. If your URLs do not carry a slug, call the recorder yourself:

```php
public function __construct(private ViewRecorder $views) {}

$this->views->record($article, $request);
```

With `symfony/messenger` installed, each view is dispatched as an `ArticleViewed` message and counted by its handler. Route it to an async transport to keep writes off the request:

```yaml
framework:
    messenger:
        routing:
            MattLeads\AgentPublishingBundle\Analytics\ArticleViewed: async
```

Without Messenger, the view is counted synchronously after the response has been sent.

```yaml
mattleads_agent_publishing:
    analytics:
        enabled: true
        timezone: Europe/London     # defines a "day"
        site_host: example.com      # links from here count as internal; defaults to the request host
```

## Bring your own storage

```yaml
mattleads_agent_publishing:
    storage: custom
    article_storage: App\Publishing\ArticleStorage     # implements ArticleStorageInterface
    api_key_storage: App\Publishing\ApiKeyStorage      # implements ApiKeyStorageInterface
    stats_storage: App\Publishing\StatsStorage         # implements StatsStorageInterface
```

Your `ArticleStorageInterface` returns objects implementing `ArticleInterface`, which is read-only. All mutation goes through the storage, so the bundle never touches your entities directly.

## License

MIT
