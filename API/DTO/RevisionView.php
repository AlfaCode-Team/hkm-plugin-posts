<?php

declare(strict_types=1);

namespace Plugins\Posts\API\DTO;

/**
 * RevisionView — one saved version of a post.
 *
 * `snapshot` is the full create/update input of that version; it is null on
 * history LISTS (which only need who/when/what title) and filled when one
 * revision is fetched, e.g. to diff against the current post.
 */
final readonly class RevisionView
{
    /** @param array<string, mixed>|null $snapshot */
    public function __construct(
        public int $number,
        public string $title,
        public ?string $note,
        public ?string $createdBy,
        public string $createdAt,
        public ?array $snapshot,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
