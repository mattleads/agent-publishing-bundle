<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Api;

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
                ['name' => 'sections'], ['name' => 'articles'], ['name' => 'workflow'],
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
