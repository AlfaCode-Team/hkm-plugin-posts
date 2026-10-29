<?php

declare(strict_types=1);

namespace Plugins\Posts\Domain\Rules;

/**
 * PostTextRule — the optional, length-bounded text fields of a post.
 *
 * Announcements gives each such field its own value object; a post has ten of
 * them with identical behaviour (trim, empty → null, cap the length), so they
 * share one rule and differ only in the limit and the label in the message.
 * Each limit matches its column in the `posts` migration.
 */
final class PostTextRule
{
    public const int SUBTITLE_MAX         = 200;
    public const int EXCERPT_MAX          = 500;
    public const int COVER_ALT_MAX        = 200;
    public const int AUTHOR_ID_MAX        = 64;
    public const int AUTHOR_NAME_MAX      = 120;
    public const int DATELINE_MAX         = 120;
    public const int CONTACT_MAX          = 500;
    public const int SOURCE_NAME_MAX      = 120;
    public const int META_TITLE_MAX       = 160;
    public const int META_DESCRIPTION_MAX = 320;

    private function __construct()
    {
        // Static rule holder — never instantiated.
    }

    /** null/'' (after trimming) → null. Otherwise at most `$max` characters. */
    public static function optional(mixed $value, int $max, string $label): ?string
    {
        if (is_array($value) || is_object($value)) {
            throw new \DomainException("{$label} must be text.");
        }

        $trimmed = trim((string) ($value ?? ''));

        if ($trimmed === '') {
            return null;
        }

        if (mb_strlen($trimmed) > $max) {
            throw new \DomainException("{$label} must not exceed {$max} characters.");
        }

        return $trimmed;
    }
}
