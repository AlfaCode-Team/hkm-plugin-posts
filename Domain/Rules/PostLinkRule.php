<?php

declare(strict_types=1);

namespace Plugins\Posts\Domain\Rules;

/**
 * PostLinkRule — the two kinds of URL a post stores.
 *
 *   source URL   where press coverage was published — always another site,
 *                so it must be absolute http(s);
 *   cover image  either absolute http(s) (a CDN) or a root-relative path the
 *                site itself serves (`/storage/covers/x.webp`, what an upload
 *                through the Storage plugin usually yields).
 *
 * Both reject every other scheme. A host drops these straight into `href` /
 * `src`, so `javascript:` or `data:` getting through here would be script
 * injection with the plugin's blessing.
 */
final class PostLinkRule
{
    public const int URL_MAX_LENGTH = 500;

    private function __construct()
    {
        // Static rule holder — never instantiated.
    }

    /** null/'' → none. Otherwise an absolute http(s) URL. */
    public static function absoluteUrl(mixed $value, string $label): ?string
    {
        $trimmed = self::trimmed($value, $label);

        if ($trimmed === null) {
            return null;
        }

        if (!preg_match('#^https?://#i', $trimmed) || filter_var($trimmed, FILTER_VALIDATE_URL) === false) {
            throw new \DomainException("{$label} must be an absolute http(s) URL.");
        }

        return $trimmed;
    }

    /** null/'' → none. Otherwise an absolute http(s) URL or a root-relative path. */
    public static function imageSource(mixed $value, string $label): ?string
    {
        $trimmed = self::trimmed($value, $label);

        if ($trimmed === null) {
            return null;
        }

        // `//host/x` is protocol-relative — an absolute URL to ANY host in
        // disguise — so a root-relative path must not start with two slashes.
        if (str_starts_with($trimmed, '/') && !str_starts_with($trimmed, '//')) {
            if (preg_match('/[\s\x00-\x1F\x7F\\\\]/', $trimmed) === 1) {
                throw new \DomainException("{$label} must not contain spaces, control characters or backslashes.");
            }

            return $trimmed;
        }

        return self::absoluteUrl($trimmed, $label);
    }

    private static function trimmed(mixed $value, string $label): ?string
    {
        if (is_array($value) || is_object($value)) {
            throw new \DomainException("{$label} must be text.");
        }

        $trimmed = trim((string) ($value ?? ''));

        if ($trimmed === '') {
            return null;
        }

        if (mb_strlen($trimmed) > self::URL_MAX_LENGTH) {
            throw new \DomainException("{$label} must not exceed " . self::URL_MAX_LENGTH . ' characters.');
        }

        return $trimmed;
    }
}
