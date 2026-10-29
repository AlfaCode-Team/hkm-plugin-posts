<?php

declare(strict_types=1);

namespace Plugins\Posts\API\Contracts;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use Plugins\Posts\API\DTO\PostDetail;
use Plugins\Posts\API\DTO\PostPage;
use Plugins\Posts\API\DTO\PostView;
use Plugins\Posts\API\DTO\RevisionView;

/**
 * Published contract for `post.publishing` — blog posts, articles and press
 * releases, told apart by `kind` ('blog' | 'article' | 'press').
 *
 * This contract performs NO authorization: it trusts its caller, and takes the
 * acting user as `$actorId`. The plugin's own admin routes put a permission
 * check in front of it (PostEditorialService); a project calling it directly
 * owns that check.
 *
 * Three families:
 *
 *   ADMIN   every status, the trash included. Never expose on a public route.
 *   PUBLIC  only posts that are published, not trashed, and whose
 *           publication date is not after `$now`. Take `$now` explicitly so the
 *           caller (or a test) decides what "now" means.
 *   OPS     scheduled work: announce due posts, empty the trash, re-render.
 *
 * Writes dispatch `post.published` / `post.updated` / `post.unpublished` /
 * `post.deleted` after they commit.
 */
interface PostServiceContract
{
    /** @return list<string> The kinds this plugin accepts. */
    public function kinds(): array;

    // ── admin ────────────────────────────────────────────────────────────

    /**
     * Admin listing.
     *
     * @param string|null $status   null = everything but the trash | 'draft' | 'scheduled' | 'published' | 'trash'.
     * @param string|null $category A category SLUG; includes its descendants.
     * @param string|null $authorId Posts written OR created by this user.
     * @param string      $order    'created' (default) | 'updated' | 'published' | 'views'.
     * @throws ValidationException An unknown kind, status, locale, category or order.
     */
    public function list(
        ?string $kind = null,
        ?string $status = null,
        ?string $tag = null,
        ?string $search = null,
        int $page = 1,
        int $perPage = 20,
        ?string $locale = null,
        ?string $category = null,
        ?string $authorId = null,
        string $order = 'created',
    ): PostPage;

    /**
     * How many posts match these filters in each status: what the admin's
     * status tabs show. Same filters as list(), minus status, in one query.
     *
     * @return array{draft: int, scheduled: int, published: int, trash: int}
     * @throws ValidationException An unknown kind, locale or category.
     */
    public function statusCounts(
        ?string $kind = null,
        ?string $tag = null,
        ?string $search = null,
        ?string $locale = null,
        ?string $category = null,
        ?string $authorId = null,
    ): array;

    /** Any status, trash included, with its body. Null when no post has this id. */
    public function find(string $publicId): ?PostView;

    /**
     * Create a post. `translationOf` (a post id) makes it a translation of
     * that post — it must be in a locale the group does not have yet.
     *
     * @param array<string, mixed> $input See the README for every key.
     * @throws ValidationException Every field error at once, keyed as the input.
     */
    public function create(array $input, string $actorId): PostView;

    /**
     * Full replace of the editable fields, except: an omitted `slug` keeps the
     * current slug, and an omitted `publishedAt` keeps the current date. A
     * changed slug leaves a redirect behind, so old links keep working.
     *
     * @param array<string, mixed> $input
     * @param string|null $note Recorded on the revision this save creates.
     * @throws ValidationException
     * @return PostView|null Null when no post has this id. A trashed post can be edited.
     */
    public function update(string $publicId, array $input, string $actorId, ?string $note = null): ?PostView;

    /** Move to the trash. Restorable. False when no post had this id or it already was. */
    public function trash(string $publicId, string $actorId): bool;

    /** Take out of the trash, exactly as it was. Null when no trashed post has this id. */
    public function restore(string $publicId, string $actorId): ?PostView;

    /** Delete permanently, with its tags, categories, revisions and redirects. */
    public function purge(string $publicId): bool;

    /** A new DRAFT with this post's content, "(copy)" appended to its title. */
    public function duplicate(string $publicId, string $actorId): ?PostView;

    /** @return list<RevisionView> Newest first, without snapshots. */
    public function revisions(string $publicId, int $limit = 50): array;

    /** One revision with its full snapshot. */
    public function revision(string $publicId, int $number): ?RevisionView;

    /** Save the post as it was at revision `$number` — itself a new revision. */
    public function restoreRevision(string $publicId, int $number, string $actorId): ?PostView;

    /** @return list<PostView> Every language version in the post's group, this one included, any status but trash. */
    public function translations(string $publicId): array;

    /**
     * Posts per kind per status, for a dashboard.
     *
     * @return array<string, array<string, int>> kind => status => count
     */
    public function stats(): array;

    /** A slug for `$title` that is free in this kind and locale. */
    public function suggestSlug(string $title, string $kind = 'blog', string $locale = '', ?string $exceptPublicId = null): string;

    /** A link token that lets anyone holding it read this post — draft or not — until it expires. */
    public function previewToken(string $publicId, int $ttlSeconds = 86_400): string;

    /** The post a valid preview token opens (trashed posts excluded). */
    public function findForPreview(string $token, \DateTimeImmutable $now): ?PostView;

    // ── public ───────────────────────────────────────────────────────────

    /**
     * Public listing, newest publication first.
     *
     * @param int|null $year,$month Restrict to one month (`month` needs `year`) — a month archive.
     * @param string|null $author The BYLINE's author id — an author archive. Matches the
     *                            credited author only, never who typed the post.
     * @throws ValidationException An unknown kind, locale or category; a bad year/month.
     */
    public function published(
        \DateTimeImmutable $now,
        ?string $kind = null,
        ?string $tag = null,
        ?string $search = null,
        bool $featuredOnly = false,
        int $page = 1,
        int $perPage = 10,
        ?string $locale = null,
        ?string $category = null,
        ?int $year = null,
        ?int $month = null,
        ?string $author = null,
    ): PostPage;

    /**
     * Public read of one post by its address. Null for an unknown kind or
     * slug, a draft, a scheduled post or a trashed one — all the same 404 to a
     * reader. A null locale matches any locale, preferring the default.
     */
    public function findPublished(string $kind, string $slug, \DateTimeImmutable $now, ?string $locale = null): ?PostView;

    /** {@see self::findPublished()} plus previous/next, related posts and published translations. */
    public function show(string $kind, string $slug, \DateTimeImmutable $now, ?string $locale = null, int $relatedLimit = 3): ?PostDetail;

    /**
     * When `findPublished()` finds nothing: the visible post this address
     * USED to belong to, so the host can answer 301 instead of 404.
     */
    public function redirectFor(string $kind, string $slug, \DateTimeImmutable $now, ?string $locale = null): ?PostView;

    /** @return list<PostView> Most-read public posts. */
    public function popular(\DateTimeImmutable $now, ?string $kind = null, ?string $locale = null, int $limit = 5): array;

    /** @return list<array{tag: string, count: int}> Tags in use on public posts, most used first. */
    public function tags(\DateTimeImmutable $now, ?string $kind = null, int $limit = 50, ?string $locale = null): array;

    /** @return list<array{year: int, month: int, count: int}> Months with public posts, newest first. */
    public function archive(\DateTimeImmutable $now, ?string $kind = null, ?string $locale = null): array;

    /** Count one read of a public post. False when the post is not public. */
    public function recordView(string $publicId, \DateTimeImmutable $now): bool;

    // ── ops ──────────────────────────────────────────────────────────────

    /**
     * Send `post.published` for every post whose scheduled time has arrived
     * and has not been announced. Safe to run concurrently — each post is
     * claimed before it is announced. Returns how many were announced.
     */
    public function publishDue(\DateTimeImmutable $now, int $limit = 100): int;

    /** Purge posts trashed before `$before`. Returns how many. */
    public function emptyTrash(\DateTimeImmutable $before, int $limit = 200): int;

    /**
     * Re-render stored bodies (after a renderer upgrade), a batch at a time.
     *
     * @return array{processed: int, last: ?string} Pass `last` back as `$after` to continue; null when done.
     */
    public function rerender(int $limit = 100, ?string $after = null): array;
}
