<?php

declare(strict_types=1);

namespace Plugins\Posts\Infrastructure\Persistence;

use Plugins\Posts\Domain\ValueObjects\PostKind;
use Plugins\Posts\Domain\ValueObjects\PostStatus;

/**
 * PostCriteria — what a listing filters and sorts on, already validated by the
 * service.
 *
 * `status` is computed, not stored, but it is a pure function of
 * `deleted_at`, `is_published` and `published_at` against `$now`, so it still
 * filters in SQL — which is what lets a listing page and count without
 * loading every row. A null status means "anything but the trash".
 */
final readonly class PostCriteria
{
    public const string ORDER_CREATED   = 'created';
    public const string ORDER_PUBLISHED = 'published';
    public const string ORDER_UPDATED   = 'updated';
    public const string ORDER_VIEWS     = 'views';

    /**
     * @param list<string>|null $categoryIds Posts in ANY of these (already expanded to descendants).
     */
    public function __construct(
        public \DateTimeImmutable $now,
        public ?PostKind $kind = null,
        public ?PostStatus $status = null,
        public ?string $locale = null,
        public ?string $tag = null,
        public ?array $categoryIds = null,
        public ?string $search = null,
        public ?string $authorId = null,
        public bool $featuredOnly = false,
        public ?\DateTimeImmutable $publishedFrom = null,
        public ?\DateTimeImmutable $publishedBefore = null,
        public string $order = self::ORDER_CREATED,
        /**
         * The credited author only. Unlike authorId (an editor's "my posts",
         * which also matches whoever created the row), this is safe to expose
         * publicly: it never reveals who typed a post bylined to someone else.
         */
        public ?string $bylineId = null,
    ) {
    }
}
