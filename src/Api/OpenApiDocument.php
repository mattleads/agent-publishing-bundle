<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Api;

use MattLeads\AgentPublishingBundle\Analytics\VisitKind;
use MattLeads\AgentPublishingBundle\Lint\LintLevel;
use MattLeads\AgentPublishingBundle\Model\ArticleFilter;
use MattLeads\AgentPublishingBundle\Model\ArticleInput;
use MattLeads\AgentPublishingBundle\Model\ArticleState;
use MattLeads\AgentPublishingBundle\Model\Scope;
use MattLeads\AgentPublishingBundle\Workflow\ArticleWorkflow;

/**
 * Builds the OpenAPI 3.1 description of the REST API. Kept in PHP so it cannot drift from the enums and the
 * workflow table it is generated from.
 */
final class OpenApiDocument
{
    public const VERSION = '0.1';

    /** @return array<string, mixed> */
    public static function build(string $prefix): array
    {
        $states = array_map(static fn(ArticleState $s) => $s->value, ArticleState::cases());
        $article = ['$ref' => '#/components/schemas/Article'];
        $problem = ['$ref' => '#/components/schemas/Problem'];
        $id = ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'Article id (ULID).'];
        $json = static fn(array $schema, string $description) => ['description' => $description, 'content' => ['application/json' => ['schema' => $schema]]];
        $errors = static fn(array $extra = []) => $extra + [
            '401' => $json($problem, 'Missing, invalid or revoked API key.'),
            '403' => $json($problem, 'The key lacks the required scope or cannot access the section.'),
        ];

        $paths = [
            $prefix . '/sections' => [
                'get' => [
                    'operationId' => 'listSections',
                    'summary' => 'Sections and locales the key may write to',
                    'tags' => ['sections'],
                    'x-scope' => Scope::ArticlesRead->value,
                    'responses' => $errors(['200' => $json(['$ref' => '#/components/schemas/SectionList'], 'Sections visible to this key.')]),
                ],
            ],
            $prefix . '/articles' => [
                'get' => [
                    'operationId' => 'listArticles',
                    'summary' => 'List articles, newest updated first',
                    'tags' => ['articles'],
                    'x-scope' => Scope::ArticlesRead->value,
                    'parameters' => [
                        ['name' => 'section', 'in' => 'query', 'schema' => ['type' => 'string']],
                        ['name' => 'locale', 'in' => 'query', 'schema' => ['type' => 'string']],
                        ['name' => 'state', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => $states]],
                        ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
                        ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => ArticleFilter::MAX_PER_PAGE, 'default' => 20]],
                    ],
                    'responses' => $errors([
                        '200' => $json(['$ref' => '#/components/schemas/ArticleList'], 'A page of articles.'),
                        '422' => $json($problem, 'Invalid query.'),
                    ]),
                ],
                'post' => [
                    'operationId' => 'createArticle',
                    'summary' => 'Create a draft',
                    'tags' => ['articles'],
                    'x-scope' => Scope::ArticlesDraft->value,
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ArticleCreate']]]],
                    'responses' => $errors([
                        '201' => $json($article, 'The created draft.'),
                        '422' => $json($problem, 'Validation failed. See "violations".'),
                    ]),
                ],
            ],
            $prefix . '/articles/{id}' => [
                'get' => [
                    'operationId' => 'getArticle',
                    'summary' => 'Read one article',
                    'tags' => ['articles'],
                    'x-scope' => Scope::ArticlesRead->value,
                    'parameters' => [$id],
                    'responses' => $errors(['200' => $json($article, 'The article.'), '404' => $json($problem, 'Unknown id.')]),
                ],
                'patch' => [
                    'operationId' => 'updateArticle',
                    'summary' => 'Change fields of a draft or an article in review',
                    'tags' => ['articles'],
                    'x-scope' => Scope::ArticlesDraft->value,
                    'parameters' => [$id],
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ArticleUpdate']]]],
                    'responses' => $errors([
                        '200' => $json($article, 'The updated article.'),
                        '404' => $json($problem, 'Unknown id.'),
                        '409' => $json($problem, 'The article is published, scheduled or archived and can no longer be edited.'),
                        '422' => $json($problem, 'Validation failed. See "violations".'),
                    ]),
                ],
            ],
        ];

        $transitionDocs = [
            'submit' => ['Submit a draft for review', 'draft → in_review'],
            'approve' => ['Approve an article in review and publish it', 'in_review → published'],
            'reject' => ['Send an article in review back to draft with a reason', 'in_review → draft'],
            'publish' => ['Publish now, or schedule when "publish_at" is in the future', 'draft, in_review or scheduled → published, or → scheduled'],
            'archive' => ['Archive a published or scheduled article', 'published or scheduled → archived'],
        ];
        foreach (ArticleWorkflow::TRANSITIONS as $name => $definition) {
            $body = match ($name) {
                'reject' => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['reason' => ['type' => 'string', 'description' => 'Why the article was rejected. Stored as review_note.']]]]]],
                'publish' => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['publish_at' => ['type' => 'string', 'format' => 'date-time', 'description' => 'When in the future, the article is scheduled instead of published.']]]]]],
                default => null,
            };
            $operation = [
                'operationId' => $name . 'Article',
                'summary' => $transitionDocs[$name][0],
                'description' => 'Transition: ' . $transitionDocs[$name][1] . '.',
                'tags' => ['workflow'],
                'x-scope' => $definition['scope']->value,
                'parameters' => [$id],
                'responses' => $errors([
                    '200' => $json($article, 'The article after the transition.'),
                    '404' => $json($problem, 'Unknown id.'),
                    '409' => $json($problem, 'The transition is not allowed from the current state.'),
                    '422' => $json($problem, 'Invalid body.'),
                ]),
            ];
            if (null !== $body) {
                $operation['requestBody'] = $body;
            }
            $paths[$prefix . '/articles/{id}/' . $name] = ['post' => $operation];
        }

        $lintReport = ['$ref' => '#/components/schemas/LintReport'];
        $paths[$prefix . '/articles/{id}/lint'] = [
            'post' => [
                'operationId' => 'lintArticle',
                'summary' => 'Check a stored article against the SEO and answer-engine rules',
                'description' => 'Read-only. Returns one check per rule with pass, warn or fail. Fix the fails and warns, then submit.',
                'tags' => ['lint'],
                'x-scope' => Scope::ArticlesRead->value,
                'parameters' => [$id],
                'responses' => $errors([
                    '200' => $json($lintReport, 'The report.'),
                    '404' => $json($problem, 'Unknown id.'),
                ]),
            ],
        ];
        $paths[$prefix . '/lint'] = [
            'post' => [
                'operationId' => 'lintPayload',
                'summary' => 'Check an article before creating it',
                'description' => 'Same rules as lintArticle, applied to a body you have not saved yet. Nothing is stored.',
                'tags' => ['lint'],
                'x-scope' => Scope::ArticlesRead->value,
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ArticleCreate']]]],
                'responses' => $errors([
                    '200' => $json($lintReport, 'The report.'),
                    '422' => $json($problem, 'Validation failed. See "violations".'),
                ]),
            ],
        ];

        $keyId = ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'Key id (ULID).'];
        $apiKey = ['$ref' => '#/components/schemas/ApiKey'];
        $paths[$prefix . '/keys'] = [
            'get' => [
                'operationId' => 'listKeys',
                'summary' => 'Keys this key may manage',
                'description' => 'Secrets are never returned. A section-restricted key sees only keys inside its sections.',
                'tags' => ['keys'],
                'x-scope' => Scope::KeysManage->value,
                'responses' => $errors(['200' => $json(['type' => 'object', 'required' => ['items', 'total'], 'properties' => ['items' => ['type' => 'array', 'items' => $apiKey], 'total' => ['type' => 'integer']]], 'Keys.')]),
            ],
            'post' => [
                'operationId' => 'createKey',
                'summary' => 'Issue a key',
                'description' => 'The new key may hold only a subset of your scopes and sections. The secret is in the response once and never again.',
                'tags' => ['keys'],
                'x-scope' => Scope::KeysManage->value,
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ApiKeyCreate']]]],
                'responses' => $errors([
                    '201' => $json(['$ref' => '#/components/schemas/ApiKeyCreated'], 'The key, with its secret.'),
                    '422' => $json($problem, 'Validation failed, including scopes or sections you cannot grant.'),
                ]),
            ],
        ];
        $paths[$prefix . '/keys/{id}'] = [
            'get' => [
                'operationId' => 'getKey',
                'summary' => 'Read a key',
                'tags' => ['keys'],
                'x-scope' => Scope::KeysManage->value,
                'parameters' => [$keyId],
                'responses' => $errors(['200' => $json($apiKey, 'The key.'), '404' => $json($problem, 'Unknown id, or outside your sections.')]),
            ],
            'delete' => [
                'operationId' => 'revokeKey',
                'summary' => 'Revoke a key',
                'description' => 'Revocation is immediate and permanent. The key row stays for the audit trail.',
                'tags' => ['keys'],
                'x-scope' => Scope::KeysManage->value,
                'parameters' => [$keyId],
                'responses' => $errors(['200' => $json($apiKey, 'The revoked key.'), '404' => $json($problem, 'Unknown id, or outside your sections.')]),
            ],
        ];

        $period = [
            ['name' => 'from', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date'], 'description' => 'First day, inclusive. Defaults to 29 days before "to".'],
            ['name' => 'to', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date'], 'description' => 'Last day, inclusive. Defaults to today in the analytics timezone.'],
        ];
        $sectionParam = ['name' => 'section', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Limit to one section.'];
        $paths[$prefix . '/articles/{id}/stats'] = [
            'get' => [
                'operationId' => 'getArticleStats',
                'summary' => 'Views per day and referrers of one article',
                'tags' => ['stats'],
                'x-scope' => Scope::StatsRead->value,
                'parameters' => [$id, ...$period],
                'responses' => $errors([
                    '200' => $json(['$ref' => '#/components/schemas/ArticleStats'], 'Counts for the period.'),
                    '404' => $json($problem, 'Unknown id.'),
                    '422' => $json($problem, 'Invalid period.'),
                ]),
            ],
        ];
        $paths[$prefix . '/stats/summary'] = [
            'get' => [
                'operationId' => 'getStatsSummary',
                'summary' => 'Site or section totals by visit kind',
                'tags' => ['stats'],
                'x-scope' => Scope::StatsRead->value,
                'parameters' => [$sectionParam, ...$period],
                'responses' => $errors([
                    '200' => $json(['$ref' => '#/components/schemas/StatsSummary'], 'Totals for the period.'),
                    '422' => $json($problem, 'Invalid query.'),
                ]),
            ],
        ];
        $paths[$prefix . '/stats/referrers'] = [
            'get' => [
                'operationId' => 'getStatsReferrers',
                'summary' => 'Inbound counts per referrer host',
                'tags' => ['stats'],
                'x-scope' => Scope::StatsRead->value,
                'parameters' => [$sectionParam, ...$period, ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50]]],
                'responses' => $errors([
                    '200' => $json(['$ref' => '#/components/schemas/ReferrerList'], 'Referrers, most views first.'),
                    '422' => $json($problem, 'Invalid query.'),
                ]),
            ],
        ];

        $writable = [
            'title' => ['type' => 'string', 'maxLength' => 200],
            'slug' => ['type' => 'string', 'maxLength' => 200, 'pattern' => '^[a-z0-9]+(?:-[a-z0-9]+)*$', 'description' => 'Derived from the title when omitted. Unique per section and locale.'],
            'summary' => ['type' => 'string', 'maxLength' => 500],
            'body' => ['type' => 'string', 'description' => 'Markdown by default. See "format".'],
            'format' => ['type' => 'string', 'enum' => ArticleInput::FORMATS, 'default' => 'markdown'],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
            'translation_group' => ['type' => 'string', 'maxLength' => 64, 'description' => 'Shared by the translations of one article.'],
        ];

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Agent Publishing API',
                'version' => self::VERSION,
                'description' => 'Draft, review, publish and read articles on a Symfony site. Send the API key as "Authorization: Bearer apb_...". Each operation names the scope it needs in "x-scope".',
            ],
            'security' => [['apiKey' => []]],
            'tags' => [
                ['name' => 'sections'], ['name' => 'articles'], ['name' => 'workflow'], ['name' => 'lint'], ['name' => 'stats'], ['name' => 'keys'],
            ],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'apiKey' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'An API key issued by the site, prefixed "apb_".'],
                ],
                'schemas' => [
                    'Problem' => [
                        'type' => 'object',
                        'required' => ['error'],
                        'properties' => [
                            'error' => ['type' => 'string'],
                            'violations' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Present on 422 responses.'],
                        ],
                    ],
                    'Section' => [
                        'type' => 'object',
                        'required' => ['name', 'path', 'locales'],
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'path' => ['type' => 'string'],
                            'locales' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                    ],
                    'SectionList' => [
                        'type' => 'object',
                        'required' => ['items', 'locales', 'default_locale'],
                        'properties' => [
                            'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Section']],
                            'locales' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'default_locale' => ['type' => 'string'],
                        ],
                    ],
                    'ArticleCreate' => [
                        'type' => 'object',
                        'required' => ['section', 'locale', 'title', 'body'],
                        'properties' => ['section' => ['type' => 'string'], 'locale' => ['type' => 'string']] + $writable,
                    ],
                    'ArticleUpdate' => [
                        'type' => 'object',
                        'description' => 'Any subset of the writable fields. Section and locale cannot change.',
                        'properties' => $writable,
                    ],
                    'Article' => [
                        'type' => 'object',
                        'required' => ['id', 'section', 'locale', 'title', 'slug', 'body', 'format', 'tags', 'state', 'transitions', 'created_at', 'updated_at'],
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'section' => ['type' => 'string'],
                            'locale' => ['type' => 'string'],
                        ] + $writable + [
                            'summary' => ['type' => ['string', 'null']],
                            'translation_group' => ['type' => ['string', 'null']],
                            'state' => ['type' => 'string', 'enum' => $states],
                            'transitions' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ArticleWorkflow::transitions()], 'description' => 'Transitions allowed from the current state, before checking the key scope.'],
                            'review_note' => ['type' => ['string', 'null'], 'description' => 'Reason given on the last reject.'],
                            'created_by' => ['type' => ['string', 'null'], 'description' => 'Name of the API key that created the article.'],
                            'created_at' => ['type' => 'string', 'format' => 'date-time'],
                            'updated_at' => ['type' => 'string', 'format' => 'date-time'],
                            'published_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                            'publish_at' => ['type' => ['string', 'null'], 'format' => 'date-time', 'description' => 'Set while the state is "scheduled".'],
                        ],
                    ],
                    'ApiKey' => [
                        'type' => 'object',
                        'required' => ['id', 'name', 'scopes', 'sections', 'created_at', 'active'],
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'name' => ['type' => 'string'],
                            'scopes' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => Scope::values()]],
                            'sections' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Empty means every section.'],
                            'created_at' => ['type' => 'string', 'format' => 'date-time'],
                            'last_used_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                            'expires_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                            'revoked_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                            'active' => ['type' => 'boolean', 'description' => 'Neither revoked nor expired.'],
                        ],
                    ],
                    'ApiKeyCreate' => [
                        'type' => 'object',
                        'required' => ['name', 'scopes'],
                        'properties' => [
                            'name' => ['type' => 'string', 'maxLength' => 100, 'description' => 'Who holds the key.'],
                            'scopes' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string', 'enum' => Scope::values()]],
                            'sections' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Restrict to these sections. Required when your own key is restricted.'],
                            'expires_at' => ['type' => 'string', 'format' => 'date-time', 'description' => 'Optional. The key stops working at this time.'],
                        ],
                    ],
                    'ApiKeyCreated' => [
                        'allOf' => [
                            $apiKey,
                            ['type' => 'object', 'required' => ['secret'], 'properties' => ['secret' => ['type' => 'string', 'description' => 'Shown once. Store it now.']]],
                        ],
                    ],
                    'LintCheck' => [
                        'type' => 'object',
                        'required' => ['rule', 'level', 'message'],
                        'properties' => [
                            'rule' => ['type' => 'string'],
                            'level' => ['type' => 'string', 'enum' => array_map(static fn(LintLevel $l) => $l->value, LintLevel::cases())],
                            'message' => ['type' => 'string', 'description' => 'What was found.'],
                            'hint' => ['type' => 'string', 'description' => 'How to fix it. Present on warn and fail.'],
                        ],
                    ],
                    'LintReport' => [
                        'type' => 'object',
                        'required' => ['status', 'summary', 'checks'],
                        'properties' => [
                            'article_id' => ['type' => 'string', 'description' => 'Present when a stored article was checked.'],
                            'status' => ['type' => 'string', 'enum' => ['pass', 'warn', 'fail'], 'description' => 'The worst level among the checks.'],
                            'summary' => ['type' => 'object', 'required' => ['pass', 'warn', 'fail'], 'properties' => ['pass' => ['type' => 'integer'], 'warn' => ['type' => 'integer'], 'fail' => ['type' => 'integer']]],
                            'checks' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/LintCheck']],
                        ],
                    ],
                    'Referrer' => [
                        'type' => 'object',
                        'required' => ['host', 'kind', 'views'],
                        'properties' => [
                            'host' => ['type' => 'string', 'description' => 'Normalized referrer host. Empty for direct and crawler views.'],
                            'kind' => ['type' => 'string', 'enum' => VisitKind::values()],
                            'views' => ['type' => 'integer'],
                        ],
                    ],
                    'StatsSummary' => [
                        'type' => 'object',
                        'required' => ['from', 'to', 'views', 'crawler_views', 'by_kind'],
                        'properties' => [
                            'section' => ['type' => ['string', 'null']],
                            'from' => ['type' => 'string', 'format' => 'date'],
                            'to' => ['type' => 'string', 'format' => 'date'],
                            'views' => ['type' => 'integer', 'description' => 'Human views.'],
                            'crawler_views' => ['type' => 'integer', 'description' => 'Views by AI crawlers and other bots.'],
                            'by_kind' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer'], 'description' => 'Views per visit kind.'],
                        ],
                    ],
                    'ArticleStats' => [
                        'type' => 'object',
                        'required' => ['article_id', 'from', 'to', 'views', 'crawler_views', 'by_kind', 'per_day', 'referrers'],
                        'properties' => [
                            'article_id' => ['type' => 'string'],
                            'from' => ['type' => 'string', 'format' => 'date'],
                            'to' => ['type' => 'string', 'format' => 'date'],
                            'views' => ['type' => 'integer'],
                            'crawler_views' => ['type' => 'integer'],
                            'by_kind' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
                            'per_day' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['day', 'views', 'crawler_views'], 'properties' => ['day' => ['type' => 'string', 'format' => 'date'], 'views' => ['type' => 'integer'], 'crawler_views' => ['type' => 'integer']]]],
                            'referrers' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Referrer']],
                        ],
                    ],
                    'ReferrerList' => [
                        'type' => 'object',
                        'required' => ['from', 'to', 'items'],
                        'properties' => [
                            'section' => ['type' => ['string', 'null']],
                            'from' => ['type' => 'string', 'format' => 'date'],
                            'to' => ['type' => 'string', 'format' => 'date'],
                            'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Referrer']],
                        ],
                    ],
                    'ArticleList' => [
                        'type' => 'object',
                        'required' => ['items', 'total', 'page', 'per_page'],
                        'properties' => [
                            'items' => ['type' => 'array', 'items' => $article],
                            'total' => ['type' => 'integer'],
                            'page' => ['type' => 'integer'],
                            'per_page' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
