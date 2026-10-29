<?php

declare(strict_types=1);

namespace Plugins\Posts\Domain\ValueObjects;

/**
 * PostKind — which stream a post belongs to (`posts.kind`).
 *
 * One table serves all three because they share every column that matters —
 * a title, a slug, a body, an author, a publication date. What differs is
 * where a site shows them (`/blog`, `/articles`, `/press`) and which optional
 * fields an editor tends to fill (a press release has a dateline and a media
 * contact; press COVERAGE has a source name and URL). None of those fields is
 * forbidden on another kind — a column a kind does not use simply stays null.
 *
 * Stored as a plain varchar, not a database enum, so adding a kind here is a
 * code change only — no migration.
 *
 * A slug is unique PER KIND: `/blog/launch` and `/press/launch` may coexist.
 */
enum PostKind: string
{
    case Blog    = 'blog';
    case Article = 'article';
    case Press   = 'press';

    /** null/'' → the default kind (`blog`). */
    public static function fromNullable(mixed $value): self
    {
        $trimmed = strtolower(trim((string) ($value ?? '')));

        if ($trimmed === '') {
            return self::Blog;
        }

        return self::tryFrom($trimmed)
            ?? throw new \DomainException('Kind must be one of: ' . implode(', ', self::values()) . '.');
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn(self $kind): string => $kind->value, self::cases());
    }
}
