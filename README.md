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

With `storage: doctrine`, run a migration: the bundle maps `agent_publishing_article` and `agent_publishing_api_key`.

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
| `stats:read` | counts and referrers (planned) |
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

## Bring your own storage

```yaml
mattleads_agent_publishing:
    storage: custom
    article_storage: App\Publishing\ArticleStorage     # implements ArticleStorageInterface
    api_key_storage: App\Publishing\ApiKeyStorage      # implements ApiKeyStorageInterface
```

Your `ArticleStorageInterface` returns objects implementing `ArticleInterface`, which is read-only. All mutation goes through the storage, so the bundle never touches your entities directly.

## License

MIT
