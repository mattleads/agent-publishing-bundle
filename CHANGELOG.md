# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- MCP endpoint at `{prefix}/mcp`: a dependency-free, tools-only Model Context Protocol server (JSON-RPC 2.0 over HTTP) exposing sections, style guide, articles, lint, workflow transitions and stats as tools, filtered by key scope. Configurable under `mcp`; extensible via the `mattleads_agent_publishing.mcp_tool` tag.
- Key management over the API: `GET/POST /keys`, `GET/DELETE /keys/{id}` under `keys:manage`. A key can only grant a subset of its own scopes and sections; restricted keys only see keys inside their sections.
- `api.key_management: false` switches the `/keys` endpoints off (404, absent from OpenAPI) so keys are issued only from the console.
- Key expiry: `expires_at` on keys, `--expires` on the console command; expired keys are refused like revoked ones. Doctrine users need a migration adding `expires_at` to `agent_publishing_api_key`.

## [0.1.1] - 2026-11-06

### Security
- Section-restricted keys get 404 for articles in other sections, must name a section for listings and totals when restricted to several, and default to their only section otherwise.
- Tags are limited to 20 of at most 64 characters each.
- `ApiKeyManager` refuses an empty `kernel.secret`.

### Changed
- IDE files removed from the repository.

## [0.1.0] - 2026-11-06

### Added
- Article contracts (`ArticleInterface`, `ArticleStorageInterface`) with bundled in-memory and Doctrine storages.
- Sections and locales declared in bundle configuration.
- Article workflow: draft, in_review, scheduled, published, archived, with `ArticleTransitioned` events.
- Scoped API keys (`articles:read`, `articles:draft`, `articles:approve`, `articles:publish`, `stats:read`, `keys:manage`), a bearer-token authenticator and the `agent-publishing:api-key:create` command.
- REST API: sections, article list/create/read/update, submit, approve, reject, publish, archive.
- OpenAPI 3.1 document at `{prefix}/openapi.json`, generated from the same enums and workflow table as the code.
- SEO output: `agent_publishing_jsonld()` and `agent_publishing_url()` Twig functions backed by `JsonLdBuilder`, a sitemap with hreflang alternates at `/sitemap-publisher.xml`, and `/llms.txt`. URLs come from a configurable pattern (`{base}`, `{locale_prefix}`, `{section_path}`, `{slug}`, ...) or a host `UrlResolverInterface`.
- Lint: `POST /articles/{id}/lint` and `POST /lint` run eleven SEO and answer-engine rules on Markdown or HTML bodies and return pass/warn/fail per rule with fix hints. Rules are extensible via the `mattleads_agent_publishing.lint_rule` tag; per-section expectations under `sections.*.lint`.
- Analytics: daily view and referrer rollups, visit classification (direct, internal, referral, search, social, ai_referral, ai_crawler, bot), a terminate-time listener keyed off the `_agent_publishing_section` route default, optional Messenger dispatch, and three `stats:read` endpoints.
- Scheduled publishing: `agent-publishing:publish-scheduled` command for cron, or an optional Symfony Scheduler task (`scheduler.enabled`).

[Unreleased]: https://github.com/mattleads/agent-publishing-bundle/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/mattleads/agent-publishing-bundle/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/mattleads/agent-publishing-bundle/releases/tag/v0.1.0
