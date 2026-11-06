<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Model;

/**
 * Fields an API client may set on an article. Null means "not provided" and leaves the stored value untouched.
 */
final class ArticleInput
{
    public const FORMATS = ['markdown', 'html'];
    public const MAX_TAGS = 20;
    public const MAX_TAG_LENGTH = 64;
    private const FIELDS = ['section', 'locale', 'title', 'slug', 'summary', 'body', 'format', 'tags', 'translation_group'];

    /** @param list<string>|null $tags */
    public function __construct(
        public readonly ?string $section = null,
        public readonly ?string $locale = null,
        public readonly ?string $title = null,
        public readonly ?string $slug = null,
        public readonly ?string $summary = null,
        public readonly ?string $body = null,
        public readonly ?string $format = null,
        public readonly ?array $tags = null,
        public readonly ?string $translationGroup = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     *
     * @throws ValidationException
     */
    public static function fromArray(array $data, bool $partial = false): self
    {
        $violations = [];
        foreach (array_keys($data) as $key) {
            if (!\in_array($key, self::FIELDS, true)) {
                $violations[] = \sprintf('Unknown field "%s".', $key);
            }
        }

        $string = static function (string $field, int $max, bool $required) use ($data, $partial, &$violations): ?string {
            if (!\array_key_exists($field, $data) || null === $data[$field]) {
                if ($required && !$partial) {
                    $violations[] = \sprintf('Field "%s" is required.', $field);
                }

                return null;
            }
            if (!\is_string($data[$field])) {
                $violations[] = \sprintf('Field "%s" must be a string.', $field);

                return null;
            }
            $value = trim($data[$field]);
            if ('' === $value && $required) {
                $violations[] = \sprintf('Field "%s" must not be empty.', $field);

                return null;
            }
            if (mb_strlen($value) > $max) {
                $violations[] = \sprintf('Field "%s" must be at most %d characters.', $field, $max);

                return null;
            }

            return $value;
        };

        $section = $string('section', 64, true);
        $locale = $string('locale', 8, true);
        $title = $string('title', 200, true);
        $slug = $string('slug', 200, false);
        $summary = $string('summary', 500, false);
        $body = $string('body', 1_000_000, true);
        $format = $string('format', 16, false);
        $translationGroup = $string('translation_group', 64, false);

        if (null !== $slug && !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            $violations[] = 'Field "slug" must contain only lowercase letters, digits and single dashes.';
        }
        if (null !== $format && !\in_array($format, self::FORMATS, true)) {
            $violations[] = \sprintf('Field "format" must be one of: %s.', implode(', ', self::FORMATS));
        }

        $tags = null;
        if (\array_key_exists('tags', $data) && null !== $data['tags']) {
            if (!\is_array($data['tags']) || false === array_is_list($data['tags'])) {
                $violations[] = 'Field "tags" must be a list of strings.';
            } else {
                $tags = [];
                foreach ($data['tags'] as $tag) {
                    if (!\is_string($tag) || '' === trim($tag)) {
                        $violations[] = 'Field "tags" must be a list of non-empty strings.';
                        break;
                    }
                    if (mb_strlen(trim($tag)) > self::MAX_TAG_LENGTH) {
                        $violations[] = \sprintf('Each tag must be at most %d characters.', self::MAX_TAG_LENGTH);
                        break;
                    }
                    $tags[] = trim($tag);
                }
                $tags = array_values(array_unique($tags));
                if (\count($tags) > self::MAX_TAGS) {
                    $violations[] = \sprintf('At most %d tags are allowed.', self::MAX_TAGS);
                }
            }
        }

        if ([] !== $violations) {
            throw new ValidationException($violations);
        }

        return new self($section, $locale, $title, $slug, $summary, $body, $format, $tags, $translationGroup);
    }
}
