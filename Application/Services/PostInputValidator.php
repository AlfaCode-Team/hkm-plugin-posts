<?php

declare(strict_types=1);

namespace Plugins\Posts\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use Plugins\Posts\Domain\Rules\PostLinkRule;
use Plugins\Posts\Domain\Rules\PostScheduleRule;
use Plugins\Posts\Domain\Rules\PostTextRule;
use Plugins\Posts\Domain\ValueObjects\BodyFormat;
use Plugins\Posts\Domain\ValueObjects\CategoryId;
use Plugins\Posts\Domain\ValueObjects\PostBody;
use Plugins\Posts\Domain\ValueObjects\PostContent;
use Plugins\Posts\Domain\ValueObjects\PostKind;
use Plugins\Posts\Domain\ValueObjects\PostLocale;
use Plugins\Posts\Domain\ValueObjects\PostSlug;
use Plugins\Posts\Domain\ValueObjects\PostTag;
use Plugins\Posts\Domain\ValueObjects\PostTitle;

/**
 * PostInputValidator — raw create/update input → validated {@see PostContent}.
 *
 * Collects EVERY field error before throwing: the contract promises all of
 * them at once, each keyed exactly as its input key (`tags.3`, not `tags`).
 * Nothing here touches the database — checks that need it (a slug already
 * taken, a category that does not exist) happen in the service afterwards.
 *
 * The slug in the returned content is PROVISIONAL when none was given
 * (derived from the title); the service settles it against storage.
 */
final class PostInputValidator
{
    /** A post filed in more places than this is not categorised, it is spammed. */
    public const int MAX_CATEGORIES = 5;

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function validate(array $input): PostContent
    {
        $errors = [];

        $field = static function (string $key, callable $check) use (&$errors): mixed {
            try {
                return $check();
            } catch (\DomainException $e) {
                $errors[$key] = $e->getMessage();

                return null;
            }
        };

        $kind   = $field('kind', static fn() => PostKind::fromNullable(self::scalar($input['kind'] ?? null, 'Kind')));
        $locale = $field('locale', static fn() => PostLocale::fromNullable($input['locale'] ?? null));
        $title  = $field('title', static fn() => PostTitle::fromString(self::scalar($input['title'] ?? null, 'Title')));

        $slug = $field('slug', static function () use ($input): ?PostSlug {
            $raw = self::scalar($input['slug'] ?? null, 'Slug');

            return trim($raw) === '' ? null : PostSlug::fromString($raw);
        });

        $text = static fn(string $key, int $max, string $label) =>
            $field($key, static fn() => PostTextRule::optional($input[$key] ?? null, $max, $label));

        $subtitle        = $text('subtitle', PostTextRule::SUBTITLE_MAX, 'Subtitle');
        $excerpt         = $text('excerpt', PostTextRule::EXCERPT_MAX, 'Excerpt');
        $coverImageAlt   = $text('coverImageAlt', PostTextRule::COVER_ALT_MAX, 'Cover image alt text');
        $authorId        = $text('authorId', PostTextRule::AUTHOR_ID_MAX, 'Author id');
        $authorName      = $text('authorName', PostTextRule::AUTHOR_NAME_MAX, 'Author name');
        $dateline        = $text('dateline', PostTextRule::DATELINE_MAX, 'Dateline');
        $contact         = $text('contact', PostTextRule::CONTACT_MAX, 'Contact');
        $sourceName      = $text('sourceName', PostTextRule::SOURCE_NAME_MAX, 'Source name');
        $metaTitle       = $text('metaTitle', PostTextRule::META_TITLE_MAX, 'Meta title');
        $metaDescription = $text('metaDescription', PostTextRule::META_DESCRIPTION_MAX, 'Meta description');

        $body       = $field('body', static fn() => PostBody::fromNullable(self::scalar($input['body'] ?? null, 'Body')));
        $bodyFormat = $field('bodyFormat', static fn() => BodyFormat::fromNullable(self::scalar($input['bodyFormat'] ?? null, 'Body format')));

        $coverImageUrl = $field('coverImageUrl', static fn() => PostLinkRule::imageSource($input['coverImageUrl'] ?? null, 'Cover image URL'));
        $sourceUrl     = $field('sourceUrl', static fn() => PostLinkRule::absoluteUrl($input['sourceUrl'] ?? null, 'Source URL'));
        $canonicalUrl  = $field('canonicalUrl', static fn() => PostLinkRule::absoluteUrl($input['canonicalUrl'] ?? null, 'Canonical URL'));

        $publishedAt = $field('publishedAt', static fn() => PostScheduleRule::parseInstant($input['publishedAt'] ?? null));

        $tags       = $this->tags($input['tags'] ?? null, $errors);
        $categories = $this->categories($input['categories'] ?? null, $errors);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        /** @var PostKind $kind */
        /** @var PostLocale $locale */
        /** @var PostTitle $title */
        /** @var BodyFormat $bodyFormat */
        return new PostContent(
            kind: $kind,
            locale: $locale,
            slug: $slug ?? PostSlug::fromTitle($title->value()),
            title: $title,
            subtitle: $subtitle,
            excerpt: $excerpt,
            body: $body,
            bodyFormat: $bodyFormat,
            coverImageUrl: $coverImageUrl,
            coverImageAlt: $coverImageAlt,
            authorId: $authorId,
            authorName: $authorName,
            tags: $tags,
            categoryIds: $categories,
            dateline: $dateline,
            contact: $contact,
            sourceName: $sourceName,
            sourceUrl: $sourceUrl,
            metaTitle: $metaTitle,
            metaDescription: $metaDescription,
            canonicalUrl: $canonicalUrl,
            noindex: self::toBool($input['noindex'] ?? false),
            featured: self::toBool($input['featured'] ?? false),
            // Default ON: a form that does not show the switch must not
            // silently close comments on every post it saves.
            commentsEnabled: self::toBool($input['commentsEnabled'] ?? true),
            published: self::toBool($input['published'] ?? false),
            publishedAt: $publishedAt,
        );
    }

    /** True when the input names a slug of its own (rather than leaving it to be derived). */
    public static function givesSlug(array $input): bool
    {
        $raw = $input['slug'] ?? null;

        return is_scalar($raw) && trim((string) $raw) !== '';
    }

    /**
     * Tags arrive as a list (`["news", "Mobile Money"]`, from JSON) or as one
     * comma-separated string (`"news, Mobile Money"`, from a plain form field).
     * Each is normalised and de-duplicated; a bad one is reported at its
     * position, `tags.{n}`, and too many as `tags`.
     *
     * @param array<string, string> $errors
     * @return list<PostTag>
     */
    private function tags(mixed $raw, array &$errors): array
    {
        $list = self::listOf($raw, 'tags', 'Tags', $errors);
        $tags = [];

        foreach ($list as $i => $value) {
            if (!is_string($value) && !is_int($value)) {
                $errors["tags.{$i}"] = 'Tag must be text.';
                continue;
            }

            try {
                $tag = PostTag::fromString((string) $value);
            } catch (\DomainException $e) {
                $errors["tags.{$i}"] = $e->getMessage();
                continue;
            }

            $tags[$tag->value()] = $tag;
        }

        if (count($tags) > PostTag::MAX_PER_POST) {
            $errors['tags'] = 'A post may have at most ' . PostTag::MAX_PER_POST . ' tags.';
        }

        return array_values($tags);
    }

    /**
     * Category public ids, as a list or a comma-separated string. Only their
     * FORMAT is checked here; that they exist is the service's job.
     *
     * @param array<string, string> $errors
     * @return list<string>
     */
    private function categories(mixed $raw, array &$errors): array
    {
        $ids = [];

        foreach (self::listOf($raw, 'categories', 'Categories', $errors) as $i => $value) {
            try {
                $ids[CategoryId::fromString(is_scalar($value) ? (string) $value : '')->value()] = true;
            } catch (\DomainException) {
                $errors["categories.{$i}"] = 'Category must be a category id.';
            }
        }

        if (count($ids) > self::MAX_CATEGORIES) {
            $errors['categories'] = 'A post may be in at most ' . self::MAX_CATEGORIES . ' categories.';
        }

        return array_keys($ids);
    }

    /**
     * @param array<string, string> $errors
     * @return list<mixed>
     */
    private static function listOf(mixed $raw, string $key, string $label, array &$errors): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        if (is_string($raw)) {
            return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn(string $t): bool => $t !== ''));
        }

        if (!is_array($raw)) {
            $errors[$key] = "{$label} must be a list or a comma-separated string.";

            return [];
        }

        return array_values($raw);
    }

    /**
     * A string field's raw value, or a DomainException when it is a list or
     * an object — which `(string)` would otherwise turn into "Array" with
     * only a warning.
     */
    private static function scalar(mixed $value, string $label): string
    {
        if ($value === null) {
            return '';
        }

        if (!is_scalar($value)) {
            throw new \DomainException("{$label} must be text.");
        }

        return (string) $value;
    }

    public static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
        }

        return false;
    }
}
