# AgentPublishingBundle, design (draft 2, 6 Nov 2026)

Name: `mattleads/agent-publishing-bundle`, namespace `MattLeads\AgentPublishingBundle`, config key `mattleads_agent_publishing` (decided 6 Nov 2026; the name was free on Packagist).

## One line

Add the bundle to any Symfony site and it exposes an API and an MCP server through which webmasters, AI agents and marketing platforms publish articles, approve drafts and read traffic and referrer counts. No frontend. Everything is managed through YAML and the API.

## Decisions taken on 6 Nov 2026

| Question | Decision |
| --- | --- |
| Who owns article storage | Both. A bundled Doctrine storage for new sites, and an adapter contract for sites with their own entities. Chosen in YAML. |
| First consumers | Marketcells and well365.io, both as API/MCP clients. |
| Sites per install | One site per install, with multiple sections and multiple languages. |
| Analytics scope | View counts and inbound counts per referrer. No revenue attribution, no raw event storage, no cookies. |
| Approval | Through the API. The bundle ships no admin UI. |
| Frontend | None in this version. Templates, forms and Stimulus controllers are out of scope. |

## Who uses it

- **Webmaster**: installs the bundle, maps sections and locales in YAML, issues API keys with scopes, approves drafts from any HTTP client or from their own backend.
- **AI agent**: discovers tools over MCP, drafts an article into a section and locale, runs the lint tool until the SEO/AEO checklist passes, submits for review, reads stats later to learn what worked.
- **Platform** (Marketcells, well365.io): same as the agent but over REST with an OpenAPI spec, publishing on behalf of many customers, each with their own key.

## Architecture

```
Host Symfony app
├── AgentPublishingBundle
│   ├── Contract/            ArticleStorageInterface, ArticleInterface, SectionResolverInterface
│   ├── Storage/Doctrine/    default entities (Article, ArticleTranslation, ApiKey, DailyStat, ReferrerStat)
│   ├── Api/                 REST controllers, OpenAPI document, request/response DTOs
│   ├── Mcp/                 tools exposed through symfony/mcp-bundle
│   ├── Workflow/            draft → in_review → published → archived (symfony/workflow)
│   ├── Security/            ApiKeyAuthenticator, scopes, rate limiting
│   ├── Analytics/           ViewListener → Messenger → RollupHandler
│   └── Seo/                 JSON-LD, sitemap, llms.txt, lint rules
└── config/packages/publisher.yaml
```

### Storage modes

```yaml
# config/packages/mattleads_agent_publishing.yaml
mattleads_agent_publishing:
    storage: doctrine            # or: custom
    custom_storage: App\Publisher\ArticleStorage   # when storage: custom
    locales: [en, de, ru]
    default_locale: en
    sections:
        blog:    { path: /blog,  locales: [en, de] }
        docs:    { path: /docs,  locales: [en] }
        news:    { path: /news }                     # all locales
```

With `storage: doctrine` the bundle registers its entities and the host runs a migration. With `storage: custom` the host implements `ArticleStorageInterface` (find, list, create, update, transition, delete) and `ArticleInterface` on its own entity. The API, MCP, workflow and analytics never touch Doctrine directly, they only call the contract. This is the same shape as `TracksCredentialUsageInterface` in the passkeys bundle: one small contract, the bundle does the rest.

### Sections and locales

An article belongs to one section and one locale, with an optional `translation_group` id that links its translations. Sections are YAML, not database rows, so an agent cannot invent a section and a deploy can review the list. The URL is built by the host through `SectionResolverInterface::urlFor(article)`, because only the host knows its routing.

### Workflow

States: `draft`, `in_review`, `published`, `archived`. Transitions: `submit`, `approve`, `reject` (back to draft with a reason), `publish` (approve and publish in one step for keys that hold `articles:publish`), `archive`. Implemented with `symfony/workflow` so the host can hook listeners (notify Slack on `submit`, warm a cache on `publish`). Scheduled publishing is a `publish_at` field checked by a Scheduler task.

### API keys and scopes

Keys are hashed in storage and shown once at creation. Scopes:

| Scope | Allows |
| --- | --- |
| `articles:read` | list and read articles, any state |
| `articles:draft` | create and update drafts, submit for review |
| `articles:approve` | approve, reject |
| `articles:publish` | approve and publish without review, archive |
| `stats:read` | counts and referrers |
| `keys:manage` | issue and revoke keys (webmaster only) |

A Marketcells key would typically carry `articles:draft` and `stats:read`. The webmaster decides whether to grant `articles:publish` to an agent. Rate limiting per key uses `symfony/rate-limiter`. Keys can be restricted to sections.

### REST API

Base path `/api/publisher/v1`, JSON, OpenAPI 3.1 document served at `/api/publisher/v1/openapi.json`.

```
GET    /sections
GET    /articles?section=&locale=&state=&page=
POST   /articles                         draft
GET    /articles/{id}
PATCH  /articles/{id}
POST   /articles/{id}/submit
POST   /articles/{id}/approve
POST   /articles/{id}/reject             { reason }
POST   /articles/{id}/publish            { publish_at? }
POST   /articles/{id}/archive
POST   /articles/{id}/lint               SEO/AEO checklist, no state change
GET    /articles/{id}/stats?from=&to=    views per day, referrers
GET    /stats/summary?from=&to=          site-wide
GET    /stats/referrers?from=&to=        inbound counts per referrer and per section
POST   /keys, DELETE /keys/{id}          keys:manage
```

Article body is Markdown by default, with `format: html` accepted. Images are URLs; upload is out of scope for 0.1 (agents already host images elsewhere, and this keeps storage simple).

### MCP server

Served at `/mcp/publisher` through `symfony/mcp-bundle` (1.0.0-BETA1 on 6 Nov 2026, built on the official `mcp/sdk`). The bundle keeps `symfony/mcp-bundle` as an optional dependency: REST works without it. Tools mirror the REST verbs and add two agent conveniences:

- `publisher.list_sections`, `publisher.list_articles`, `publisher.get_article`
- `publisher.create_draft`, `publisher.update_draft`, `publisher.submit`, `publisher.approve`, `publisher.publish`
- `publisher.lint` returns a pass/fail list the agent can act on
- `publisher.stats` and `publisher.top_referrers`
- `publisher.style_guide` returns the site's writing rules from YAML, so an agent writes in the site's voice without a prompt engineer

Same API keys, same scopes, sent as a bearer token.

### Analytics

A `kernel.response` listener on routes the host marks as article pages (via `SectionResolverInterface::articleForRequest()`), dispatching a `PageViewed` message to Messenger so the request is not slowed. The handler increments a daily rollup row per article and a referrer rollup row per (day, referrer host, section). Classification happens in the handler:

- `human`, `search` (Google, Bing, DuckDuckGo referrers), `social`, `ai_referral` (chatgpt.com, perplexity.ai, claude.ai, gemini.google.com referrers), `ai_crawler` (GPTBot, ClaudeBot, PerplexityBot, Google-Extended, CCBot by user agent), `other_bot`.

No cookies, no IP stored, no per-visitor identity. Counts only, which keeps it outside consent banners in most readings of GDPR and matches the "counts and referrers" decision. The `ai_referral` and `ai_crawler` buckets are the GEO/AEO feature: a webmaster sees which articles AI systems read and which AI products send readers.

### SEO, GEO and AEO output

- JSON-LD `Article` and `BreadcrumbList` as a Twig function the host drops into its layout (`{{ publisher_jsonld(article) }}`), the only Twig this bundle ships.
- `/sitemap-publisher.xml` per section and locale with `hreflang` alternates from the translation group.
- `/llms.txt` listing sections and recent articles with summaries.
- Lint rules (each returns pass, warn or fail with a message): title length, meta description present and under 160 chars, one H1, headings in order, first paragraph answers the title (AEO), FAQ block present when the section asks for it, canonical set, images have alt text, internal links at least N, reading level, no duplicate slug in the section, translation group consistency.

Lint is where agents get value: they can loop until green before submitting, which is what makes "automate the job and improve SEO" true rather than a slogan.

## Non-goals for 0.1

- No admin UI, forms or Stimulus controllers.
- No media upload.
- No comments, tags taxonomy beyond a simple `tags: []` field, or multi-site.
- No raw event log or per-visitor analytics.
- No built-in LLM calls. The bundle is the publishing surface agents call, it does not call agents.

## Testing

Same bar as the other two bundles: PHPUnit with a kernel test app under `tests/App`, PHPStan level 8, CI matrix on PHP 8.2/8.3/8.4 and Symfony 7.4/8.1. API tests through `KernelBrowser`, MCP tests by calling the tool classes directly, analytics tests with the in-memory Messenger transport. A demo repo `publisher-demo` with the Doctrine storage, a two-section, two-locale site, and a script that plays an agent: draft, lint, fix, submit, approve, read stats.

## 90-day scope

| Weeks | Deliverable |
| --- | --- |
| 1-2 | Contracts, Doctrine storage, workflow, API keys. Tagged 0.1.0 with REST read and draft endpoints. |
| 3-4 | Approval, publish, archive, scheduled publish. OpenAPI document. 0.2.0. |
| 5-6 | Analytics listener, rollups, classification, stats endpoints. 0.3.0. |
| 7-8 | Lint rules, JSON-LD, sitemap, llms.txt. 0.4.0. |
| 9-10 | MCP server over symfony/mcp-bundle, style guide tool. 0.5.0. |
| 11-12 | Demo repo, Marketcells and well365.io integrations as the two first external users, article "Let an agent run your Symfony blog, safely". 1.0.0-beta. |

## Evidence value for the Global Talent case

- Innovation: an agent-facing publishing layer for Symfony with AEO analytics, which has no equivalent in the ecosystem on 6 Nov 2026.
- Recognition: two external products (Marketcells, well365.io) integrating it, which can be shown as adoption outside the author's own demo.
- Contribution: MCP integration gives a reason to file issues or PRs against symfony/mcp-bundle while it is in beta.

## Decided after draft 1

- Body format: Markdown by default, `format: html` accepted.
- Default key scopes: `articles:read` and `articles:draft`. Publishing rights are granted explicitly.
- States: `draft`, `in_review`, `scheduled`, `published`, `archived`. A future `publish_at` moves to `scheduled`.
- Workflow implemented in the bundle (`ArticleWorkflow`) with an `ArticleTransitioned` event rather than `symfony/workflow`, to keep the dependency list short. Can be revisited.

## Open questions

1. Marketcells disclosure: when the bundle is public and Marketcells is listed as an integrator, the relationship should be stated in the README.
