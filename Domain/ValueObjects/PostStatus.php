<?php

declare(strict_types=1);

namespace Plugins\Posts\Domain\ValueObjects;

/**
 * PostStatus — computed at READ time against a clock, never stored.
 *
 * `posts` has no `status` column: whether a post is in the trash, a draft,
 * waiting for its publication date, or out follows entirely from
 * `deleted_at`, `is_published` and `published_at`, so persisting it would just
 * be a cache that can drift from the columns that decide it.
 *
 * Precedence: trash beats everything — a trashed post is invisible whatever
 * its flags say, and restoring it brings back exactly the state it had.
 *
 * Boundary: `published_at == now` is PUBLISHED, not scheduled — a post is
 * visible the instant its publication time is reached.
 *
 * A published post always has a `published_at` (the aggregate stamps it on
 * first publication), so a null date here can only come from a row written
 * outside this plugin; it is treated as already out rather than hidden.
 */
enum PostStatus: string
{
    case Draft     = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Trashed   = 'trash';

    public static function resolve(
        bool $published,
        ?\DateTimeImmutable $publishedAt,
        \DateTimeImmutable $now,
        ?\DateTimeImmutable $deletedAt = null,
    ): self {
        if ($deletedAt !== null) {
            return self::Trashed;
        }

        if (!$published) {
            return self::Draft;
        }

        if ($publishedAt !== null && $publishedAt > $now) {
            return self::Scheduled;
        }

        return self::Published;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn(self $status): string => $status->value, self::cases());
    }
}
