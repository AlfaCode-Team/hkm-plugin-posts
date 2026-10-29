<?php

declare(strict_types=1);

namespace Plugins\Posts\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\OptimisticLockException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Posts\Domain\Entities\Post;
use Plugins\Posts\Domain\ValueObjects\PostKind;
use Plugins\Posts\Domain\ValueObjects\PostStatus;

/**
 * PostRepository — DatabasePort ONLY. Owns `posts`, `post_tags`,
 * `post_category_links` and `post_slug_redirects`.
 *
 * The injected DatabasePort is the REQUEST's connection: the tenant database
 * on a Tenancy host, the central one otherwise. There is no `tenant_id`
 * column — the database is the tenant boundary.
 *
 * Every cross-table reference is a PUBLIC id, so an insert never needs
 * `lastInsertId()` (which on PostgreSQL needs a sequence name to guess).
 *
 * Portability rules this class keeps:
 *   - LIMIT/OFFSET are inlined validated ints, never bound: a driver with
 *     native prepares rejects `LIMIT '10'`, since a bound value is a string.
 *   - Parameters are named by {@see SqlParams} per statement, so no
 *     placeholder name is ever used twice — native prepares reject that.
 *   - Only ANSI functions: LOWER, COALESCE, COUNT, MAX. Dates are stored as
 *     `Y-m-d H:i:s` UTC strings, which compare correctly as strings, so no
 *     driver date function is needed — month archives are grouped in PHP.
 *   - Search uses `LIKE … ESCAPE '!'`, so a `%` or `_` someone types is
 *     matched literally rather than as a wildcard.
 *
 * Multi-statement writes are NOT bracketed here — the service owns the
 * transaction, over this same connection.
 */
final class PostRepository
{
    private const string POSTS     = 'posts';
    private const string TAGS      = 'post_tags';
    private const string LINKS     = 'post_category_links';
    private const string REDIRECTS = 'post_slug_redirects';
    private const string REVISIONS = 'post_revisions';

    private const string COLUMNS = 'id, public_id, kind, locale, slug, translation_group, title, subtitle, excerpt,
        summary, reading_minutes, body, body_format, body_html, toc, cover_image_url, cover_image_alt, author_id, author_name, dateline,
        contact, source_name, source_url, meta_title, meta_description, canonical_url, noindex, is_featured,
        comments_enabled, is_published, published_at, announced_at, deleted_at, view_count, version,
        created_by, updated_by, created_at, updated_at';

    /**
     * Columns a listing card needs — no body, no rendered html. A post loaded
     * this way is PARTIAL and can never be written back (see update()).
     */
    private const string CARD_COLUMNS = 'id, public_id, kind, locale, slug, translation_group, title, subtitle,
        excerpt, summary, reading_minutes, body_format, cover_image_url, cover_image_alt, author_id, author_name, dateline, contact,
        source_name, source_url, meta_title, meta_description, canonical_url, noindex, is_featured,
        comments_enabled, is_published, published_at, announced_at, deleted_at, view_count, version,
        created_by, updated_by, created_at, updated_at';

    /** Hard ceiling on one page, whatever a caller asks for. */
    public const int MAX_PER_PAGE = 100;

    /** Month archives scan publication dates; past this a site needs a real index, not a sidebar. */
    private const int ARCHIVE_SCAN_CAP = 50_000;

    public function __construct(
        private readonly DatabasePort $db,
    ) {
    }

    // ── writes ───────────────────────────────────────────────────────────

    public function insert(Post $post): void
    {
        $row     = $this->toRow($post);
        $columns = array_keys($row);

        $this->write(
            'INSERT INTO ' . self::POSTS . ' (' . implode(', ', $columns) . ')
             VALUES (' . implode(', ', array_map(static fn(string $c): string => ':' . $c, $columns)) . ')',
            $row,
            'Failed to insert post.',
            $post->id()->value(),
        );
    }

    /** Every column but the immutable ones and the view counter. False when the row is gone. */
    public function update(Post $post): bool
    {
        if ($post->isPartial()) {
            throw new \LogicException('Refusing to save a post loaded without its body — it would erase the body.');
        }

        $row = $this->toRow($post);
        unset($row['created_at'], $row['created_by'], $row['view_count']);

        // Compare-and-set on the version it was loaded at.
        $row['loaded_version'] = $post->version();
        $row['version']        = $post->version() + 1;

        $sets = [];
        foreach (array_keys($row) as $column) {
            if ($column !== 'public_id' && $column !== 'loaded_version') {
                $sets[] = "{$column} = :{$column}";
            }
        }

        $written = $this->write(
            'UPDATE ' . self::POSTS . ' SET ' . implode(', ', $sets) . ' WHERE public_id = :public_id AND version = :loaded_version',
            $row,
            'Failed to update post.',
            $post->id()->value(),
        );

        if ($written > 0) {
            $post->advanceVersion();

            return true;
        }

        // No row matched: either it is gone, or someone saved since it was read.
        $current = $this->select(
            'SELECT version FROM ' . self::POSTS . ' WHERE public_id = :public_id',
            ['public_id' => $post->id()->value()],
            'Failed to check the post version.',
        )[0]['version'] ?? null;

        if ($current === null) {
            return false;
        }

        throw new OptimisticLockException(
            'Post changed since it was loaded.',
            layer: 'repository.posts',
            context: ['publicId' => $post->id()->value(), 'loaded' => $post->version(), 'current' => (int) $current],
        );
    }

    /**
     * Replace a post's tags. Delete-then-insert rather than a diff: a post has
     * at most ten, and the caller's transaction makes the pair atomic.
     *
     * @param list<string> $tags
     */
    public function replaceTags(string $publicId, array $tags): void
    {
        $this->replaceSet(self::TAGS, 'tag', $publicId, $tags, 'tags');
    }

    /** @param list<string> $categoryIds */
    public function replaceCategories(string $publicId, array $categoryIds): void
    {
        $this->replaceSet(self::LINKS, 'category_id', $publicId, $categoryIds, 'categories');
    }

    /**
     * Remove a post and everything that hangs off it: tags, category links,
     * revisions and old-slug redirects. False when no post had this id.
     */
    public function purge(string $publicId): bool
    {
        foreach ([self::TAGS, self::LINKS, self::REVISIONS, self::REDIRECTS] as $table) {
            $this->write(
                "DELETE FROM {$table} WHERE post_id = :post_id",
                ['post_id' => $publicId],
                "Failed to purge {$table}.",
                $publicId,
            );
        }

        return $this->write(
            'DELETE FROM ' . self::POSTS . ' WHERE public_id = :public_id',
            ['public_id' => $publicId],
            'Failed to purge post.',
            $publicId,
        ) > 0;
    }

    /** Remember that `slug` used to lead to this post. Any older claim on the same address is replaced. */
    public function addRedirect(string $kind, string $locale, string $slug, string $postId, \DateTimeImmutable $now): void
    {
        $this->removeRedirect($kind, $locale, $slug);

        $this->write(
            'INSERT INTO ' . self::REDIRECTS . ' (kind, locale, slug, post_id, created_at)
             VALUES (:kind, :locale, :slug, :post_id, :created_at)',
            ['kind' => $kind, 'locale' => $locale, 'slug' => $slug, 'post_id' => $postId, 'created_at' => self::formatInstant($now)],
            'Failed to record slug redirect.',
            $postId,
        );
    }

    /** A live post now owns this address, so it must stop redirecting. */
    public function removeRedirect(string $kind, string $locale, string $slug): void
    {
        $this->write(
            'DELETE FROM ' . self::REDIRECTS . ' WHERE kind = :kind AND locale = :locale AND slug = :slug',
            ['kind' => $kind, 'locale' => $locale, 'slug' => $slug],
            'Failed to clear slug redirect.',
            $slug,
        );
    }

    /**
     * Compare-and-set: mark a post announced only if no one else has. True
     * means THIS caller won and must send `post.published`; two scheduler runs
     * racing over one post therefore announce it once.
     */
    public function claimAnnouncement(string $publicId, \DateTimeImmutable $now): bool
    {
        return $this->write(
            'UPDATE ' . self::POSTS . ' SET announced_at = :announced_at
             WHERE public_id = :public_id AND announced_at IS NULL',
            ['announced_at' => self::formatInstant($now), 'public_id' => $publicId],
            'Failed to mark post announced.',
            $publicId,
        ) > 0;
    }

    /** Count one read of a visible post. False for a draft, a scheduled or a trashed post. */
    public function incrementViews(string $publicId, \DateTimeImmutable $now): bool
    {
        $p = new SqlParams();

        try {
            return $this->db->execute(
                'UPDATE ' . self::POSTS . ' SET view_count = view_count + 1
                 WHERE public_id = ' . $p->add($publicId) . ' AND ' . self::visible($p, $now),
                $p->all(),
            ) > 0;
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to count a post view.', layer: 'repository.posts', context: ['publicId' => $publicId], previous: $e);
        }
    }

    // ── single reads ─────────────────────────────────────────────────────

    /** Any status, the trash included. */
    public function findByPublicId(string $publicId): ?Post
    {
        $rows = $this->select(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::POSTS . ' WHERE public_id = :public_id',
            ['public_id' => $publicId],
            'Failed to load post.',
        );

        return $this->hydrateAll($rows)[0] ?? null;
    }

    /**
     * A post readers may see, by its address. A null locale matches any
     * locale, preferring the default ('' sorts first).
     */
    public function findVisible(PostKind $kind, string $slug, \DateTimeImmutable $now, ?string $locale): ?Post
    {
        $p   = new SqlParams();
        $sql = 'SELECT ' . self::COLUMNS . ' FROM ' . self::POSTS . '
                WHERE kind = ' . $p->add($kind->value) . ' AND slug = ' . $p->add($slug) . ' AND ' . self::visible($p, $now);

        if ($locale !== null) {
            $sql .= ' AND locale = ' . $p->add($locale);
        }

        $rows = $this->select($sql . ' ORDER BY locale ASC LIMIT 1', $p->all(), 'Failed to load post.');

        return $this->hydrateAll($rows)[0] ?? null;
    }

    /** The post an old address used to belong to, or null. */
    public function findRedirect(PostKind $kind, string $slug, ?string $locale): ?string
    {
        $p   = new SqlParams();
        $sql = 'SELECT post_id FROM ' . self::REDIRECTS . ' WHERE kind = ' . $p->add($kind->value) . ' AND slug = ' . $p->add($slug);

        if ($locale !== null) {
            $sql .= ' AND locale = ' . $p->add($locale);
        }

        $rows = $this->select($sql . ' ORDER BY locale ASC LIMIT 1', $p->all(), 'Failed to look up slug redirect.');

        return isset($rows[0]['post_id']) ? (string) $rows[0]['post_id'] : null;
    }

    /** True when another post of this kind and locale already has this slug — trashed posts included. */
    public function slugTaken(PostKind $kind, string $locale, string $slug, ?string $exceptPublicId = null): bool
    {
        $p   = new SqlParams();
        $sql = 'SELECT public_id FROM ' . self::POSTS . '
                WHERE kind = ' . $p->add($kind->value) . ' AND locale = ' . $p->add($locale) . ' AND slug = ' . $p->add($slug);

        if ($exceptPublicId !== null) {
            $sql .= ' AND public_id <> ' . $p->add($exceptPublicId);
        }

        return $this->select($sql . ' LIMIT 1', $p->all(), 'Failed to check post slug.') !== [];
    }

    // ── listings ─────────────────────────────────────────────────────────

    /** @return list<Post> */
    public function search(PostCriteria $criteria, int $limit, int $offset, bool $withBody = false): array
    {
        $limit  = max(1, min($limit, self::MAX_PER_PAGE));
        $offset = max(0, $offset);

        $p     = new SqlParams();
        $where = $this->where($criteria, $p);

        // A published or scheduled post always has a date, so those listings
        // sort on the bare column and walk idx_post_(kind_)visibility in order
        // instead of sorting the table. Drafts can lack one; for any set that
        // may hold them, COALESCE keeps the order identical on every driver
        // (NULL sorts first on PostgreSQL and last on MySQL).
        $dated = $criteria->status === PostStatus::Published || $criteria->status === PostStatus::Scheduled
            ? 'published_at'
            : 'COALESCE(published_at, created_at)';
        $order = match ($criteria->order) {
            PostCriteria::ORDER_PUBLISHED => "{$dated} DESC, id DESC",
            PostCriteria::ORDER_UPDATED   => 'updated_at DESC, id DESC',
            PostCriteria::ORDER_VIEWS     => "view_count DESC, {$dated} DESC, id DESC",
            default                       => 'id DESC',
        };

        $rows = $this->select(
            'SELECT ' . ($withBody ? self::COLUMNS : self::CARD_COLUMNS) . ' FROM ' . self::POSTS . $where
                . ' ORDER BY ' . $order . ' LIMIT ' . $limit . ' OFFSET ' . $offset,
            $p->all(),
            'Failed to list posts.',
        );

        return $this->hydrateAll($rows);
    }

    public function count(PostCriteria $criteria): int
    {
        $p     = new SqlParams();
        $where = $this->where($criteria, $p);

        $rows = $this->select('SELECT COUNT(*) AS aggregate FROM ' . self::POSTS . $where, $p->all(), 'Failed to count posts.');

        return isset($rows[0]) ? (int) array_values($rows[0])[0] : 0;
    }

    /**
     * How many posts match `$criteria` in each status, in ONE statement. The
     * criteria's own status is ignored: this is what the status tabs show
     * before one is picked. Each CASE is the same predicate where() uses for
     * that status, so a tab's number equals the length of its list.
     *
     * @return array{draft: int, scheduled: int, published: int, trash: int}
     */
    public function statusCounts(PostCriteria $criteria): array
    {
        $p   = new SqlParams();
        $now = self::formatInstant($criteria->now);
        $sql = 'SELECT'
            . ' SUM(CASE WHEN deleted_at IS NULL AND is_published = ' . $p->add(false) . ' THEN 1 ELSE 0 END) AS draft,'
            . ' SUM(CASE WHEN deleted_at IS NULL AND is_published = ' . $p->add(true) . ' AND published_at > ' . $p->add($now) . ' THEN 1 ELSE 0 END) AS scheduled,'
            . ' SUM(CASE WHEN deleted_at IS NULL AND is_published = ' . $p->add(true) . ' AND published_at <= ' . $p->add($now) . ' THEN 1 ELSE 0 END) AS published,'
            . ' SUM(CASE WHEN deleted_at IS NOT NULL THEN 1 ELSE 0 END) AS trash'
            . ' FROM ' . self::POSTS . $this->where($criteria, $p, withStatus: false);

        $row = $this->select($sql, $p->all(), 'Failed to count posts by status.')[0] ?? [];

        // SUM over no rows is NULL; PostgreSQL returns it as a numeric string.
        return [
            'draft'     => (int) ($row['draft'] ?? 0),
            'scheduled' => (int) ($row['scheduled'] ?? 0),
            'published' => (int) ($row['published'] ?? 0),
            'trash'     => (int) ($row['trash'] ?? 0),
        ];
    }

    /**
     * Posts by public id, in the order given; ids that do not resolve are skipped.
     *
     * @param list<string> $publicIds
     * @return list<Post>
     */
    public function findMany(array $publicIds, bool $withBody = false): array
    {
        if ($publicIds === []) {
            return [];
        }

        $p    = new SqlParams();
        $rows = $this->select(
            'SELECT ' . ($withBody ? self::COLUMNS : self::CARD_COLUMNS) . ' FROM ' . self::POSTS
                . ' WHERE public_id IN (' . $p->list($publicIds) . ')',
            $p->all(),
            'Failed to load posts.',
        );

        $byId = [];
        foreach ($this->hydrateAll($rows) as $post) {
            $byId[$post->id()->value()] = $post;
        }

        return array_values(array_filter(array_map(static fn(string $id): ?Post => $byId[$id] ?? null, $publicIds)));
    }

    /** @return list<Post> Every post in a translation group, any status but trashed. */
    public function translations(string $group): array
    {
        $rows = $this->select(
            'SELECT ' . self::CARD_COLUMNS . ' FROM ' . self::POSTS . '
             WHERE translation_group = :grp AND deleted_at IS NULL ORDER BY locale ASC, id ASC',
            ['grp' => $group],
            'Failed to load translations.',
        );

        return $this->hydrateAll($rows);
    }

    /**
     * Visible posts sharing tags or categories with `$post`, best match first.
     * Scoring happens in PHP over two small aggregate queries — one per
     * relation — rather than in one driver-specific statement.
     *
     * @return list<string> Public ids, best first.
     */
    public function relatedIds(Post $post, \DateTimeImmutable $now, int $limit): array
    {
        $content = $post->content();
        $self    = $post->id()->value();
        $scores  = [];

        foreach ([[self::TAGS, 'tag', $content->tagValues()], [self::LINKS, 'category_id', $content->categoryIds]] as [$table, $column, $values]) {
            if ($values === []) {
                continue;
            }

            $p   = new SqlParams();
            $sql = "SELECT r.post_id AS post_id, COUNT(*) AS shared, MAX(p.published_at) AS latest
                    FROM {$table} r INNER JOIN " . self::POSTS . " p ON p.public_id = r.post_id
                    WHERE r.{$column} IN (" . $p->list($values) . ')
                      AND r.post_id <> ' . $p->add($self) . '
                      AND p.kind = ' . $p->add($content->kind->value) . '
                      AND p.locale = ' . $p->add($content->locale->value()) . '
                      AND ' . self::visible($p, $now, 'p.') . '
                    GROUP BY r.post_id';

            foreach ($this->select($sql, $p->all(), 'Failed to find related posts.') as $row) {
                $id = (string) $row['post_id'];
                $scores[$id] ??= ['score' => 0, 'latest' => (string) ($row['latest'] ?? '')];
                $scores[$id]['score'] += (int) $row['shared'];
            }
        }

        uksort($scores, static fn(string $a, string $b): int =>
            [$scores[$b]['score'], $scores[$b]['latest'], $b] <=> [$scores[$a]['score'], $scores[$a]['latest'], $a]);

        return array_slice(array_map('strval', array_keys($scores)), 0, max(1, $limit));
    }

    /**
     * What a sitemap needs and nothing else: one statement, newest first,
     * indexable posts only. No tags, categories or entities. A sitemap of
     * 5,000 posts used to page through `search()` 100 rows at a time, three
     * queries per page, re-sorting the table on every page.
     *
     * @return list<array{kind: string, locale: string, slug: string, translation_group: ?string, cover_image_url: ?string, updated_at: ?string}>
     */
    public function sitemapRows(PostCriteria $criteria, int $limit): array
    {
        $p   = new SqlParams();
        $sql = 'SELECT kind, locale, slug, translation_group, cover_image_url, updated_at FROM ' . self::POSTS
            . $this->where($criteria, $p) . ' AND noindex = ' . $p->add(false)
            . ' ORDER BY published_at DESC, id DESC LIMIT ' . max(1, $limit);

        return $this->select($sql, $p->all(), 'Failed to list sitemap entries.');
    }

    /**
     * The visible posts either side of `$post` in publication order, within
     * its kind and locale. Ties on the date fall back to the row id, which is
     * creation order and the same tie-break the listings use. Not the public
     * id: two ULIDs minted in the same millisecond sort at random.
     *
     * Ids only: the caller loads both neighbours in the same batch as the
     * related posts, so tags and categories are read once, not three times.
     *
     * @return array{previous: ?string, next: ?string} public ids
     */
    public function adjacentIds(Post $post, \DateTimeImmutable $now): array
    {
        $at = $post->content()->publishedAt;
        if ($at === null) {
            return ['previous' => null, 'next' => null];
        }

        $result = [];
        foreach (['previous' => ['<', 'DESC'], 'next' => ['>', 'ASC']] as $side => [$op, $dir]) {
            $p   = new SqlParams();
            $pa  = self::formatInstant($at);
            $sql = 'SELECT public_id FROM ' . self::POSTS . '
                    WHERE kind = ' . $p->add($post->content()->kind->value) . '
                      AND locale = ' . $p->add($post->content()->locale->value()) . '
                      AND ' . self::visible($p, $now) . "
                      AND (published_at {$op} " . $p->add($pa) . "
                           OR (published_at = " . $p->add($pa) . " AND id {$op} (SELECT id FROM " . self::POSTS . ' WHERE public_id = ' . $p->add($post->id()->value()) . ")))
                    ORDER BY published_at {$dir}, id {$dir} LIMIT 1";

            $id            = $this->select($sql, $p->all(), 'Failed to find adjacent posts.')[0]['public_id'] ?? null;
            $result[$side] = $id === null ? null : (string) $id;
        }

        return ['previous' => $result['previous'], 'next' => $result['next']];
    }

    /**
     * Publication dates of visible posts, newest first — the service groups
     * them into months.
     *
     * @return list<string> `Y-m-d H:i:s` UTC
     */
    public function visibleDates(\DateTimeImmutable $now, ?PostKind $kind, ?string $locale): array
    {
        $p   = new SqlParams();
        $sql = 'SELECT published_at FROM ' . self::POSTS . ' WHERE ' . self::visible($p, $now) . ' AND published_at IS NOT NULL';

        if ($kind !== null) {
            $sql .= ' AND kind = ' . $p->add($kind->value);
        }
        if ($locale !== null) {
            $sql .= ' AND locale = ' . $p->add($locale);
        }

        $rows = $this->select($sql . ' ORDER BY published_at DESC LIMIT ' . self::ARCHIVE_SCAN_CAP, $p->all(), 'Failed to read the archive.');

        return array_map(static fn(array $row): string => (string) $row['published_at'], $rows);
    }

    /**
     * Visible, unannounced posts whose time has come — what the scheduler
     * announces. Oldest first, so a backlog drains in publication order.
     *
     * @return list<Post>
     */
    public function dueForAnnouncement(\DateTimeImmutable $now, int $limit): array
    {
        $p    = new SqlParams();
        $rows = $this->select(
            'SELECT ' . self::CARD_COLUMNS . ' FROM ' . self::POSTS . '
             WHERE announced_at IS NULL AND ' . self::visible($p, $now) . '
             ORDER BY published_at ASC, id ASC LIMIT ' . max(1, min($limit, 1000)),
            $p->all(),
            'Failed to find posts due for announcement.',
        );

        return $this->hydrateAll($rows);
    }

    /**
     * Trashed before `$cutoff` — what emptying the trash removes.
     *
     * @return list<string>
     */
    public function trashedBefore(\DateTimeImmutable $cutoff, int $limit): array
    {
        $rows = $this->select(
            'SELECT public_id FROM ' . self::POSTS . '
             WHERE deleted_at IS NOT NULL AND deleted_at < :cutoff ORDER BY deleted_at ASC LIMIT ' . max(1, min($limit, 1000)),
            ['cutoff' => self::formatInstant($cutoff)],
            'Failed to list the trash.',
        );

        return array_map(static fn(array $row): string => (string) $row['public_id'], $rows);
    }

    /**
     * Every post id, oldest first, a page at a time — for re-rendering.
     *
     * @return list<string>
     */
    public function idsAfter(?string $afterPublicId, int $limit): array
    {
        $p   = new SqlParams();
        $sql = 'SELECT public_id FROM ' . self::POSTS;
        if ($afterPublicId !== null) {
            $sql .= ' WHERE public_id > ' . $p->add($afterPublicId);
        }

        $rows = $this->select($sql . ' ORDER BY public_id ASC LIMIT ' . max(1, min($limit, 1000)), $p->all(), 'Failed to page post ids.');

        return array_map(static fn(array $row): string => (string) $row['public_id'], $rows);
    }

    /**
     * Tags on visible posts, most used first, then alphabetical.
     *
     * @return list<array{tag: string, count: int}>
     */
    public function tagCounts(\DateTimeImmutable $now, ?PostKind $kind, ?string $locale, int $limit): array
    {
        $p   = new SqlParams();
        $sql = 'SELECT t.tag AS tag, COUNT(*) AS uses
                FROM ' . self::TAGS . ' t INNER JOIN ' . self::POSTS . ' p ON p.public_id = t.post_id
                WHERE ' . self::visible($p, $now, 'p.');

        if ($kind !== null) {
            $sql .= ' AND p.kind = ' . $p->add($kind->value);
        }
        if ($locale !== null) {
            $sql .= ' AND p.locale = ' . $p->add($locale);
        }

        $sql .= ' GROUP BY t.tag ORDER BY COUNT(*) DESC, t.tag ASC LIMIT ' . max(1, min($limit, 500));

        return array_map(
            static fn(array $row): array => ['tag' => (string) $row['tag'], 'count' => (int) $row['uses']],
            $this->select($sql, $p->all(), 'Failed to count post tags.'),
        );
    }

    /**
     * Visible posts per category (direct membership only — the service rolls
     * descendants up).
     *
     * @return array<string, int> category public id => count
     */
    public function categoryCounts(\DateTimeImmutable $now, ?PostKind $kind, ?string $locale): array
    {
        $p   = new SqlParams();
        $sql = 'SELECT l.category_id AS category_id, COUNT(*) AS uses
                FROM ' . self::LINKS . ' l INNER JOIN ' . self::POSTS . ' p ON p.public_id = l.post_id
                WHERE ' . self::visible($p, $now, 'p.');

        if ($kind !== null) {
            $sql .= ' AND p.kind = ' . $p->add($kind->value);
        }
        if ($locale !== null) {
            $sql .= ' AND p.locale = ' . $p->add($locale);
        }

        $counts = [];
        foreach ($this->select($sql . ' GROUP BY l.category_id', $p->all(), 'Failed to count category posts.') as $row) {
            $counts[(string) $row['category_id']] = (int) $row['uses'];
        }

        return $counts;
    }

    /** Detach every post from a category — used when the category is deleted. */
    public function unlinkCategory(string $categoryId): void
    {
        $this->write(
            'DELETE FROM ' . self::LINKS . ' WHERE category_id = :category_id',
            ['category_id' => $categoryId],
            'Failed to unlink category.',
            $categoryId,
        );
    }

    // ── internals ────────────────────────────────────────────────────────

    /**
     * The predicate a reader-visible post satisfies.
     *
     * A published post always carries a date: Post stamps one the moment it
     * is published. So the range is a plain `published_at <= now`, with no
     * `OR published_at IS NULL` branch. That branch matched nothing a save
     * could produce, yet it turned every listing into a full scan plus sort
     * (measured on 20,000 posts: 8.6 ms down to 0.02 ms). A row written by
     * hand with no date is not listed.
     */
    private static function visible(SqlParams $p, \DateTimeImmutable $now, string $alias = ''): string
    {
        return "{$alias}deleted_at IS NULL AND {$alias}is_published = " . $p->add(true)
            . " AND {$alias}published_at <= " . $p->add(self::formatInstant($now));
    }

    private function where(PostCriteria $criteria, SqlParams $p, bool $withStatus = true): string
    {
        $clauses = [];
        $now     = self::formatInstant($criteria->now);

        if ($criteria->kind !== null) {
            $clauses[] = 'kind = ' . $p->add($criteria->kind->value);
        }

        if ($criteria->locale !== null) {
            $clauses[] = 'locale = ' . $p->add($criteria->locale);
        }

        if ($withStatus) {
            $clauses[] = match ($criteria->status) {
                PostStatus::Trashed   => 'deleted_at IS NOT NULL',
                PostStatus::Draft     => 'deleted_at IS NULL AND is_published = ' . $p->add(false),
                PostStatus::Scheduled => 'deleted_at IS NULL AND is_published = ' . $p->add(true)
                    . ' AND published_at IS NOT NULL AND published_at > ' . $p->add($now),
                PostStatus::Published => self::visible($p, $criteria->now),
                null                  => 'deleted_at IS NULL',
            };
        }

        if ($criteria->tag !== null) {
            $clauses[] = 'public_id IN (SELECT post_id FROM ' . self::TAGS . ' WHERE tag = ' . $p->add($criteria->tag) . ')';
        }

        if ($criteria->categoryIds !== null) {
            $clauses[] = $criteria->categoryIds === []
                ? '1 = 0'
                : 'public_id IN (SELECT post_id FROM ' . self::LINKS . ' WHERE category_id IN (' . $p->list($criteria->categoryIds) . '))';
        }

        if ($criteria->search !== null && $criteria->search !== '') {
            $pattern   = '%' . strtr(mb_strtolower($criteria->search), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $clauses[] = '(LOWER(title) LIKE ' . $p->add($pattern) . " ESCAPE '!'"
                . " OR LOWER(COALESCE(excerpt, '')) LIKE " . $p->add($pattern) . " ESCAPE '!'"
                . ' OR public_id IN (SELECT post_id FROM ' . self::TAGS . ' WHERE tag = ' . $p->add(mb_strtolower(trim($criteria->search))) . '))';
        }

        if ($criteria->authorId !== null) {
            $clauses[] = '(author_id = ' . $p->add($criteria->authorId) . ' OR created_by = ' . $p->add($criteria->authorId) . ')';
        }

        if ($criteria->bylineId !== null) {
            $clauses[] = 'author_id = ' . $p->add($criteria->bylineId);
        }

        if ($criteria->featuredOnly) {
            $clauses[] = 'is_featured = ' . $p->add(true);
        }

        if ($criteria->publishedFrom !== null) {
            $clauses[] = 'published_at >= ' . $p->add(self::formatInstant($criteria->publishedFrom));
        }

        if ($criteria->publishedBefore !== null) {
            $clauses[] = 'published_at < ' . $p->add(self::formatInstant($criteria->publishedBefore));
        }

        return $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses);
    }

    /** @param list<string> $values */
    private function replaceSet(string $table, string $column, string $publicId, array $values, string $what): void
    {
        $this->write(
            "DELETE FROM {$table} WHERE post_id = :post_id",
            ['post_id' => $publicId],
            "Failed to clear post {$what}.",
            $publicId,
        );

        if ($values === []) {
            return;
        }

        $p    = new SqlParams();
        $rows = [];
        foreach (array_values($values) as $value) {
            $rows[] = '(' . $p->add($publicId) . ', ' . $p->add($value) . ')';
        }

        $this->write(
            "INSERT INTO {$table} (post_id, {$column}) VALUES " . implode(', ', $rows),
            $p->all(),
            "Failed to save post {$what}.",
            $publicId,
        );
    }

    /**
     * Hydrate rows, reading every row's tags and categories in ONE query each
     * rather than one per post.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<Post>
     */
    private function hydrateAll(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn(array $row): string => (string) $row['public_id'], $rows);

        $tags       = $this->groupBy(self::TAGS, 'tag', $ids);
        $categories = $this->groupBy(self::LINKS, 'category_id', $ids);

        return array_map(
            static function (array $row) use ($tags, $categories): Post {
                $id                  = (string) $row['public_id'];
                $row['tags']         = $tags[$id] ?? [];
                $row['category_ids'] = $categories[$id] ?? [];
                $row['toc']          = isset($row['toc']) && $row['toc'] !== ''
                    ? (json_decode((string) $row['toc'], true) ?: [])
                    : [];

                return Post::reconstitute($row);
            },
            $rows,
        );
    }

    /**
     * @param list<string> $postIds
     * @return array<string, list<string>>
     */
    private function groupBy(string $table, string $column, array $postIds): array
    {
        $p    = new SqlParams();
        $rows = $this->select(
            "SELECT post_id, {$column} FROM {$table} WHERE post_id IN (" . $p->list($postIds) . ') ORDER BY id ASC',
            $p->all(),
            "Failed to load {$table}.",
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) $row['post_id']][] = (string) $row[$column];
        }

        return $grouped;
    }

    /** @return array<string, mixed> */
    private function toRow(Post $post): array
    {
        $c = $post->content();

        return [
            'public_id'         => $post->id()->value(),
            'version'           => $post->version(),
            'kind'              => $c->kind->value,
            'locale'            => $c->locale->value(),
            'slug'              => $c->slug->value(),
            'translation_group' => $post->translationGroup(),
            'title'             => $c->title->value(),
            'subtitle'          => $c->subtitle,
            'excerpt'           => $c->excerpt,
            'summary'           => mb_substr($post->summary(), 0, 300),
            'reading_minutes'   => min(65535, $post->readingMinutes()),
            'body'              => $c->body?->value(),
            'body_format'       => $c->bodyFormat->value,
            'body_html'         => $post->rendered()->html === '' ? null : $post->rendered()->html,
            'toc'               => $post->rendered()->toc === [] ? null : json_encode($post->rendered()->toc, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'cover_image_url'   => $c->coverImageUrl,
            'cover_image_alt'   => $c->coverImageAlt,
            'author_id'         => $c->authorId,
            'author_name'       => $c->authorName,
            'dateline'          => $c->dateline,
            'contact'           => $c->contact,
            'source_name'       => $c->sourceName,
            'source_url'        => $c->sourceUrl,
            'meta_title'        => $c->metaTitle,
            'meta_description'  => $c->metaDescription,
            'canonical_url'     => $c->canonicalUrl,
            'noindex'           => $c->noindex,
            'is_featured'       => $c->featured,
            'comments_enabled'  => $c->commentsEnabled,
            'is_published'      => $c->published,
            'published_at'      => self::formatNullable($c->publishedAt),
            'announced_at'      => self::formatNullable($post->announcedAt()),
            'deleted_at'        => self::formatNullable($post->deletedAt()),
            'view_count'        => $post->viewCount(),
            'created_by'        => $post->createdBy(),
            'updated_by'        => $post->updatedBy(),
            'created_at'        => self::formatInstant($post->createdAt()),
            'updated_at'        => self::formatInstant($post->updatedAt()),
        ];
    }

    /** @param array<string, mixed> $params */
    private function write(string $sql, array $params, string $message, string $subject): int
    {
        try {
            return $this->db->execute($sql, $params);
        } catch (\Throwable $e) {
            throw new RepositoryException($message, layer: 'repository.posts', context: ['subject' => $subject], previous: $e);
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function select(string $sql, array $params, string $message): array
    {
        try {
            return array_values($this->db->query($sql, $params));
        } catch (\Throwable $e) {
            throw new RepositoryException($message, layer: 'repository.posts', previous: $e);
        }
    }

    private static function formatNullable(?\DateTimeImmutable $instant): ?string
    {
        return $instant === null ? null : self::formatInstant($instant);
    }

    public static function formatInstant(\DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
