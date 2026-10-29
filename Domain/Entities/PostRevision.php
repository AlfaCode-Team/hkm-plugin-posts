<?php

declare(strict_types=1);

namespace Plugins\Posts\Domain\Entities;

/**
 * PostRevision — a snapshot of a post's editable content as it was saved
 * (`post_revisions`). Immutable: history is never edited.
 *
 * `snapshot` is in the create/update INPUT shape ({@see \Plugins\Posts\Domain\ValueObjects\PostContent::toInput()}),
 * so restoring a revision is an ordinary update with it — it is validated
 * again on the way back in, which matters when a rule has tightened since.
 *
 * `number` counts from 1 per post and never repeats, even after old
 * revisions are pruned, so "revision 12" means the same thing forever.
 */
final readonly class PostRevision
{
    /** @param array<string, mixed> $snapshot */
    public function __construct(
        public string $postId,
        public int $number,
        public array $snapshot,
        public ?string $note,
        public ?string $createdBy,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
