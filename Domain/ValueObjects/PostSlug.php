<?php

declare(strict_types=1);

namespace Plugins\Posts\Domain\ValueObjects;

/**
 * PostSlug — the URL segment (`posts.slug`, varchar(160)), unique per kind.
 *
 * Lower-case ASCII letters and digits in hyphen-separated runs — a strict
 * subset of the kernel's `{slug:slug}` route parameter type
 * (`[a-zA-Z0-9_-]+`), so a slug this class produces is always routable.
 */
final readonly class PostSlug
{
    public const int MAX_LENGTH = 160;

    private const string PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    private function __construct(private string $value)
    {
    }

    /** An editor-supplied slug — validated, never rewritten behind their back. */
    public static function fromString(string $value): self
    {
        $trimmed = trim($value);

        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw new \DomainException('Slug must not exceed ' . self::MAX_LENGTH . ' characters.');
        }

        if (preg_match(self::PATTERN, $trimmed) !== 1) {
            throw new \DomainException('Slug may contain only lower-case letters, digits and single hyphens.');
        }

        return new self($trimmed);
    }

    /**
     * Derive a slug from a title: transliterate to ASCII where the platform
     * can, lower-case, and collapse every other run of characters to one
     * hyphen. A title with nothing left after that (e.g. only CJK characters
     * on a platform without transliteration) yields `post`, which the service
     * then de-duplicates like any other collision.
     */
    public static function fromTitle(string $title): self
    {
        $slug = self::normalise($title);

        if (strlen($slug) > self::MAX_LENGTH) {
            $slug = rtrim(substr($slug, 0, self::MAX_LENGTH), '-');
        }

        return new self($slug === '' ? 'post' : $slug);
    }

    /**
     * Fold any text to slug form: ASCII letters and digits, hyphen-separated.
     * Shared with {@see PostTag} so a tag and a slug can never normalise the
     * same word differently.
     *
     * Transliteration must give the SAME answer on every platform, or a post
     * created on a developer's Mac gets a different URL from one created in
     * production. `intl`'s Transliterator does. iconv alone does not: glibc
     * turns `é` into `e`, but macOS's libiconv turns it into `'e`, which would
     * become `caf-e` here. So intl is preferred, and the accent marks libiconv
     * leaves behind are removed before the split. Apostrophes go too, which
     * also makes "Don't" `dont` rather than `don-t`.
     */
    public static function normalise(string $text): string
    {
        $ascii = $text;

        if (class_exists(\Transliterator::class)) {
            $converted = \Transliterator::create('Any-Latin; Latin-ASCII')?->transliterate($text);
            if (is_string($converted)) {
                $ascii = $converted;
            }
        } elseif (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if (is_string($converted)) {
                $ascii = $converted;
            }
        }

        $ascii = str_replace(["'", '"', '`', '^', '~', "\u{2019}"], '', $ascii);

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');
    }

    /**
     * `launch` → `launch-2`. The base is shortened first when needed, so the
     * suffixed slug still fits the column.
     */
    public function withSuffix(int $n): self
    {
        $suffix = '-' . $n;
        $base   = $this->value;

        if (strlen($base) + strlen($suffix) > self::MAX_LENGTH) {
            $base = rtrim(substr($base, 0, self::MAX_LENGTH - strlen($suffix)), '-');
        }

        return new self($base . $suffix);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
