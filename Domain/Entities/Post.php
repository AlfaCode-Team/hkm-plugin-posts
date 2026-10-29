<?php

declare(strict_types=1);

namespace Plugins\Posts\Domain\Entities;

use Plugins\Posts\Domain\ValueObjects\BodyFormat;
use Plugins\Posts\Domain\ValueObjects\PostBody;
use Plugins\Posts\Domain\ValueObjects\PostContent;
use Plugins\Posts\Domain\ValueObjects\PostId;
use Plugins\Posts\Domain\ValueObjects\PostKind;
use Plugins\Posts\Domain\ValueObjects\PostLocale;
use Plugins\Posts\Domain\ValueObjects\PostSlug;
use Plugins\Posts\Domain\ValueObjects\PostStatus;
use Plugins\Posts\Domain\ValueObjects\PostTag;
use Plugins\Posts\Domain\ValueObjects\PostTitle;
use Plugins\Posts\Domain\ValueObjects\RenderedBody;

/**
 * Post aggregate — one row of `posts` plus its tags and category links. A blog
 * post, an article and a press release are all this class; {@see PostKind}
 * tells them apart. Zero external imports.
 *
 * Rules this class owns:
 *
 * PUBLICATION DATE
 *   - Publishing without a date stamps `now`, so every published post has a
 *     `publishedAt` to sort and display by.
 *   - Re-saving an already-dated post without a date KEEPS the date it had.
 *     Otherwise fixing a typo in a two-year-old press release would move it
 *     to the top of the archive with today's date on it.
 *   - Unpublishing keeps the date too, so publishing again restores the post
 *     where it was rather than as new.
 *
 * ANNOUNCEMENT (`announcedAt`)
 *   Records that `post.published` went out for the CURRENT publication. It is
 *   cleared whenever the post stops being visible (unpublished, trashed), so
 *   the next time it becomes visible — by an edit, a restore, or simply its
 *   scheduled time arriving — it is announced again, exactly once. The service
 *   decides WHEN to announce; this class only keeps the record straight.
 *
 * TRASH
 *   A trashed post keeps every field, including its slug, so restoring it
 *   brings back exactly what was there. Only a purge removes it.
 *
 * DERIVED FIELDS
 *   `summary` (the excerpt, or the body's opening cut at a word) and
 *   `readingMinutes` are computed from the content whenever it changes and
 *   STORED, so a listing can show them without loading any bodies.
 *
 * PARTIAL
 *   A post loaded for a listing card has no body. It is marked partial, and
 *   the repository refuses to save one — saving it would erase the body.
 */
final class Post
{
    private function __construct(
        private readonly PostId $id,
        private PostContent $content,
        private RenderedBody $rendered,
        private ?string $translationGroup,
        private int $viewCount,
        private ?string $createdBy,
        private ?string $updatedBy,
        private readonly \DateTimeImmutable $createdAt,
        private \DateTimeImmutable $updatedAt,
        private ?\DateTimeImmutable $deletedAt,
        private ?\DateTimeImmutable $announcedAt,
        private string $summary = '',
        private int $readingMinutes = 1,
        private readonly bool $partial = false,
        private int $version = 1,
    ) {
        if (!$partial) {
            $this->derive();
        }
    }

    public static function create(
        PostContent $content,
        RenderedBody $rendered,
        ?string $translationGroup,
        ?string $actorId,
        \DateTimeImmutable $now,
    ): self {
        return new self(
            id: PostId::generate(),
            content: self::stampPublication($content, null, $now),
            rendered: $rendered,
            translationGroup: $translationGroup,
            viewCount: 0,
            createdBy: $actorId,
            updatedBy: $actorId,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
            announcedAt: null,
        );
    }

    /**
     * Rehydrate a stored row. `$row` keys are the raw `posts` columns, plus
     * `tags` (list of tag strings) and `category_ids` (list of category public
     * ids) the repository has already read, and `toc` already JSON-decoded.
     *
     * @param array<string, mixed> $row
     */
    public static function reconstitute(array $row): self
    {
        $tags = [];
        foreach ((array) ($row['tags'] ?? []) as $tag) {
            $tags[] = PostTag::fromString((string) $tag);
        }

        $content = new PostContent(
            kind: PostKind::fromNullable($row['kind'] ?? null),
            locale: PostLocale::fromNullable($row['locale'] ?? null),
            slug: PostSlug::fromString((string) $row['slug']),
            title: PostTitle::fromString((string) $row['title']),
            subtitle: self::nullableString($row['subtitle'] ?? null),
            excerpt: self::nullableString($row['excerpt'] ?? null),
            body: PostBody::fromNullable($row['body'] ?? null),
            bodyFormat: BodyFormat::fromNullable($row['body_format'] ?? null),
            coverImageUrl: self::nullableString($row['cover_image_url'] ?? null),
            coverImageAlt: self::nullableString($row['cover_image_alt'] ?? null),
            authorId: self::nullableString($row['author_id'] ?? null),
            authorName: self::nullableString($row['author_name'] ?? null),
            tags: $tags,
            categoryIds: array_values(array_map('strval', (array) ($row['category_ids'] ?? []))),
            dateline: self::nullableString($row['dateline'] ?? null),
            contact: self::nullableString($row['contact'] ?? null),
            sourceName: self::nullableString($row['source_name'] ?? null),
            sourceUrl: self::nullableString($row['source_url'] ?? null),
            metaTitle: self::nullableString($row['meta_title'] ?? null),
            metaDescription: self::nullableString($row['meta_description'] ?? null),
            canonicalUrl: self::nullableString($row['canonical_url'] ?? null),
            noindex: self::flag($row['noindex'] ?? 0),
            featured: self::flag($row['is_featured'] ?? 0),
            commentsEnabled: self::flag($row['comments_enabled'] ?? 1),
            published: self::flag($row['is_published'] ?? 0),
            publishedAt: self::parseStoredInstant($row['published_at'] ?? null),
        );

        $toc = [];
        foreach ((array) ($row['toc'] ?? []) as $entry) {
            if (is_array($entry)) {
                $toc[] = [
                    'level' => (int) ($entry['level'] ?? 2),
                    'id'    => (string) ($entry['id'] ?? ''),
                    'text'  => (string) ($entry['text'] ?? ''),
                ];
            }
        }

        $utcNow = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return new self(
            id: PostId::fromString((string) $row['public_id']),
            content: $content,
            rendered: new RenderedBody((string) ($row['body_html'] ?? ''), $toc),
            translationGroup: self::nullableString($row['translation_group'] ?? null),
            viewCount: max(0, (int) ($row['view_count'] ?? 0)),
            createdBy: self::nullableString($row['created_by'] ?? null),
            updatedBy: self::nullableString($row['updated_by'] ?? null),
            createdAt: self::parseStoredInstant($row['created_at'] ?? null) ?? $utcNow,
            updatedAt: self::parseStoredInstant($row['updated_at'] ?? null) ?? $utcNow,
            deletedAt: self::parseStoredInstant($row['deleted_at'] ?? null),
            announcedAt: self::parseStoredInstant($row['announced_at'] ?? null),
            summary: (string) ($row['summary'] ?? ''),
            readingMinutes: max(1, (int) ($row['reading_minutes'] ?? 1)),
            // A listing query leaves the body out; see PARTIAL above.
            partial: !array_key_exists('body', $row),
            version: max(1, (int) ($row['version'] ?? 1)),
        );
    }

    /**
     * Replace every editable field. `id`, `createdBy`, `createdAt`, the trash
     * state and the translation group are untouched; the publication date
     * follows the rule in the class docblock.
     */
    public function replaceContent(PostContent $content, RenderedBody $rendered, ?string $actorId, \DateTimeImmutable $now): void
    {
        if ($this->partial) {
            throw new \LogicException('A post loaded without its body cannot be edited.');
        }

        $this->content   = self::stampPublication($content, $this->content->publishedAt, $now);
        $this->rendered  = $rendered;
        $this->updatedBy = $actorId;
        $this->updatedAt = $now;
        $this->derive();

        // Unpublished, or moved back into the future: no longer visible, so
        // the next time it becomes visible it must be announced again.
        if (!$this->isVisibleAt($now)) {
            $this->announcedAt = null;
        }
    }

    public function trash(?string $actorId, \DateTimeImmutable $now): void
    {
        $this->deletedAt   = $now;
        $this->announcedAt = null;
        $this->updatedBy   = $actorId;
        $this->updatedAt   = $now;
    }

    public function restore(?string $actorId, \DateTimeImmutable $now): void
    {
        $this->deletedAt = null;
        $this->updatedBy = $actorId;
        $this->updatedAt = $now;
    }

    /**
     * Swap in a fresh rendering of the SAME content — after a renderer
     * upgrade. Not an edit: no author, no timestamp, no revision.
     */
    public function refreshRendering(RenderedBody $rendered): void
    {
        if ($this->partial) {
            throw new \LogicException('A post loaded without its body cannot be re-rendered.');
        }

        $this->rendered = $rendered;
    }

    public function joinTranslationGroup(string $group): void
    {
        $this->translationGroup = $group;
    }

    public function markAnnounced(\DateTimeImmutable $now): void
    {
        $this->announcedAt = $now;
    }

    public function statusAt(\DateTimeImmutable $now): PostStatus
    {
        return PostStatus::resolve($this->content->published, $this->content->publishedAt, $now, $this->deletedAt);
    }

    public function isVisibleAt(\DateTimeImmutable $now): bool
    {
        return $this->statusAt($now) === PostStatus::Published;
    }

    /** Visible now and `post.published` not yet sent for this publication. */
    public function needsAnnouncementAt(\DateTimeImmutable $now): bool
    {
        return $this->announcedAt === null && $this->isVisibleAt($now);
    }

    public function summary(): string
    {
        return $this->summary;
    }

    public function readingMinutes(): int
    {
        return $this->readingMinutes;
    }

    /** Loaded without its body (a listing card) — read-only. */
    public function isPartial(): bool
    {
        return $this->partial;
    }

    public function id(): PostId
    {
        return $this->id;
    }

    public function content(): PostContent
    {
        return $this->content;
    }

    public function rendered(): RenderedBody
    {
        return $this->rendered;
    }

    public function translationGroup(): ?string
    {
        return $this->translationGroup;
    }

    public function viewCount(): int
    {
        return $this->viewCount;
    }

    public function createdBy(): ?string
    {
        return $this->createdBy;
    }

    public function updatedBy(): ?string
    {
        return $this->updatedBy;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * The stored version this object was loaded at. Every save is conditional
     * on it, so two editors saving the same post cannot overwrite each other
     * unseen: the second one's save matches no row.
     */
    public function version(): int
    {
        return $this->version;
    }

    /** The write at version() succeeded; the row is now one ahead. */
    public function advanceVersion(): void
    {
        $this->version++;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function deletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function announcedAt(): ?\DateTimeImmutable
    {
        return $this->announcedAt;
    }

    private function derive(): void
    {
        $body = $this->content->body;

        $this->summary        = $this->content->excerpt ?? $body?->summary() ?? '';
        $this->readingMinutes = $body?->readingMinutes() ?? 1;
    }

    private static function stampPublication(
        PostContent $content,
        ?\DateTimeImmutable $previous,
        \DateTimeImmutable $now,
    ): PostContent {
        if ($content->publishedAt !== null) {
            return $content;
        }

        if ($previous !== null) {
            return $content->withPublishedAt($previous);
        }

        return $content->published
            ? $content->withPublishedAt($now->setTimezone(new \DateTimeZone('UTC')))
            : $content;
    }

    private static function flag(mixed $value): bool
    {
        return (bool) (int) $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private static function parseStoredInstant(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC'));
    }
}
