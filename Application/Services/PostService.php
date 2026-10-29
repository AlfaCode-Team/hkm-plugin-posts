<?php

declare(strict_types=1);

namespace Plugins\Posts\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\OptimisticLockException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use Plugins\Posts\API\Contracts\PostServiceContract;
use Plugins\Posts\API\DTO\PostDetail;
use Plugins\Posts\API\DTO\PostPage;
use Plugins\Posts\API\DTO\PostView;
use Plugins\Posts\API\DTO\RevisionView;
use Plugins\Posts\API\Exceptions\PostEditConflictException;
use Plugins\Posts\API\IntegrationEvents\PostDeletedIntegrationEvent;
use Plugins\Posts\API\IntegrationEvents\PostIntegrationEvent;
use Plugins\Posts\API\IntegrationEvents\PostPublishedIntegrationEvent;
use Plugins\Posts\API\IntegrationEvents\PostUnpublishedIntegrationEvent;
use Plugins\Posts\API\IntegrationEvents\PostUpdatedIntegrationEvent;
use Plugins\Posts\Application\Contracts\BodyRendererContract;
use Plugins\Posts\Application\Contracts\PostEventsContract;
use Plugins\Posts\Application\Support\PreviewTokens;
use Plugins\Posts\Domain\Entities\Post;
use Plugins\Posts\Domain\Entities\PostRevision;
use Plugins\Posts\Domain\ValueObjects\PostContent;
use Plugins\Posts\Domain\ValueObjects\PostKind;
use Plugins\Posts\Domain\ValueObjects\PostLocale;
use Plugins\Posts\Domain\ValueObjects\PostSlug;
use Plugins\Posts\Domain\ValueObjects\PostStatus;
use Plugins\Posts\Domain\ValueObjects\PostTag;
use Plugins\Posts\Domain\ValueObjects\PostTitle;
use Plugins\Posts\Domain\ValueObjects\RenderedBody;
use Plugins\Posts\Infrastructure\Persistence\PostCriteria;
use Plugins\Posts\Infrastructure\Persistence\PostRepository;
use Plugins\Posts\Infrastructure\Persistence\PostRevisionRepository;

/**
 * PostService — the single implementation of {@see PostServiceContract}.
 *
 * No `Identity`, no permission check: the contract trusts its caller (see its
 * docblock). The plugin's admin routes go through PostEditorialService, which
 * enforces permissions and then calls this.
 *
 * Every write follows one shape:
 *
 *   validate input (no DB) → check against storage (slug, categories,
 *   translation group) → render the body → ONE transaction (post, tags,
 *   categories, redirects, revision) → commit → THEN dispatch events.
 *
 * The TransactionManager is built by the Provider over the SAME DatabasePort
 * the repositories write through — not the request container's, which wraps
 * the core connection and on a Tenancy host would bracket nothing (the
 * reasoning `Plugins\Payment\Provider` documents).
 *
 * Request-scoped (the view factory caches the category tree); never static.
 */
final class PostService implements PostServiceContract
{
    /** A search term longer than any title is not a search, it is a payload. */
    private const int SEARCH_MAX = 100;

    /** Attempts at `slug-2`, `slug-3`, … before falling back to a random suffix. */
    private const int SLUG_ATTEMPTS = 50;

    public function __construct(
        private readonly PostRepository $posts,
        private readonly PostRevisionRepository $revisionStore,
        private readonly PostViewFactory $views,
        private readonly PostInputValidator $validator,
        private readonly BodyRendererContract $renderer,
        private readonly TransactionManager $transaction,
        private readonly PostEventsContract $events,
        private readonly ClockPort $clock,
        private readonly PreviewTokens $previews,
        private readonly int $maxRevisions = 50,
    ) {
    }

    public function kinds(): array
    {
        return PostKind::values();
    }

    // ── admin reads ──────────────────────────────────────────────────────

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
    ): PostPage {
        $errors = [];

        $kindFilter     = $this->kindFilter($kind, $errors);
        $localeFilter   = $this->localeFilter($locale, $errors);
        $categoryFilter = $this->categoryFilter($category, $errors);

        $statusFilter = null;
        if ($status !== null && trim($status) !== '') {
            $statusFilter = PostStatus::tryFrom(strtolower(trim($status)));
            if ($statusFilter === null) {
                $errors['status'] = 'Status must be one of: ' . implode(', ', PostStatus::values()) . '.';
            }
        }

        $orders = [PostCriteria::ORDER_CREATED, PostCriteria::ORDER_UPDATED, PostCriteria::ORDER_PUBLISHED, PostCriteria::ORDER_VIEWS];
        if (!in_array($order, $orders, true)) {
            $errors['order'] = 'Order must be one of: ' . implode(', ', $orders) . '.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $this->page(
            new PostCriteria(
                now: $this->clock->now(),
                kind: $kindFilter,
                status: $statusFilter,
                locale: $localeFilter,
                tag: $this->tagFilter($tag),
                categoryIds: $categoryFilter,
                search: $this->searchFilter($search),
                authorId: $authorId === null || trim($authorId) === '' ? null : trim($authorId),
                order: $order,
            ),
            $tag,
            $page,
            $perPage,
        );
    }

    public function statusCounts(
        ?string $kind = null,
        ?string $tag = null,
        ?string $search = null,
        ?string $locale = null,
        ?string $category = null,
        ?string $authorId = null,
    ): array {
        $errors = [];

        $kindFilter     = $this->kindFilter($kind, $errors);
        $localeFilter   = $this->localeFilter($locale, $errors);
        $categoryFilter = $this->categoryFilter($category, $errors);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        // Same rule as page(): a tag that normalises to nothing matches no
        // post, rather than dropping the filter and counting every post.
        $tagFilter = $this->tagFilter($tag);
        if ($tag !== null && trim($tag) !== '' && $tagFilter === null) {
            return ['draft' => 0, 'scheduled' => 0, 'published' => 0, 'trash' => 0];
        }

        return $this->posts->statusCounts(new PostCriteria(
            now: $this->clock->now(),
            kind: $kindFilter,
            locale: $localeFilter,
            tag: $tagFilter,
            categoryIds: $categoryFilter,
            search: $this->searchFilter($search),
            authorId: $authorId === null || trim($authorId) === '' ? null : trim($authorId),
        ));
    }

    public function find(string $publicId): ?PostView
    {
        $post = $this->posts->findByPublicId(self::normaliseId($publicId));

        return $post === null ? null : $this->views->view($post, $this->clock->now());
    }

    public function translations(string $publicId): array
    {
        $post = $this->posts->findByPublicId(self::normaliseId($publicId));
        if ($post === null) {
            return [];
        }

        $now   = $this->clock->now();
        $group = $post->translationGroup();
        $posts = $group === null ? [$post] : $this->posts->translations($group);

        return array_map(fn(Post $p): PostView => $this->views->view($p, $now, withBody: false), $posts);
    }

    public function stats(): array
    {
        $now   = $this->clock->now();
        $stats = [];

        foreach (PostKind::cases() as $kind) {
            foreach (PostStatus::cases() as $status) {
                $stats[$kind->value][$status->value] = $this->posts->count(
                    new PostCriteria(now: $now, kind: $kind, status: $status),
                );
            }
        }

        return $stats;
    }

    public function suggestSlug(string $title, string $kind = 'blog', string $locale = '', ?string $exceptPublicId = null): string
    {
        $postKind   = PostKind::tryFrom(strtolower(trim($kind))) ?? PostKind::Blog;
        $postLocale = $this->safeLocale($locale) ?? PostLocale::none();

        return $this->freeSlug(
            PostSlug::fromTitle(trim($title) === '' ? 'post' : $title),
            $postKind,
            $postLocale,
            $exceptPublicId === null ? null : self::normaliseId($exceptPublicId),
        )->value();
    }

    // ── admin writes ─────────────────────────────────────────────────────

    public function create(array $input, string $actorId): PostView
    {
        $content = $this->validator->validate($input);
        $this->assertCategoriesExist($content);

        [$group, $groupOwner] = $this->translationGroupFor($input, $content);

        $content = $this->withUniqueSlug($content, $input, null);
        $now     = $this->clock->now();
        $post    = Post::create($content, $this->render($content), $group, $actorId, $now);

        $announce = $post->needsAnnouncementAt($now);
        if ($announce) {
            $post->markAnnounced($now);
        }

        $this->inTransaction('post.create.failed', $post->id()->value(), function () use ($post, $groupOwner, $actorId, $now): void {
            $id = $post->id()->value();
            $c  = $post->content();

            $this->posts->insert($post);
            $this->posts->replaceTags($id, $c->tagValues());
            $this->posts->replaceCategories($id, $c->categoryIds);
            // A live post now owns this address; an old redirect must not shadow it.
            $this->posts->removeRedirect($c->kind->value, $c->locale->value(), $c->slug->value());

            if ($groupOwner !== null) {
                $this->posts->update($groupOwner);
            }

            $this->revisionStore->append($id, $c->toInput(), 'Created', $actorId, $now);
        });

        if ($announce) {
            $this->announce(PostPublishedIntegrationEvent::class, $post, $now);
        }

        return $this->views->view($post, $now);
    }

    public function update(string $publicId, array $input, string $actorId, ?string $note = null): ?PostView
    {
        // Field validation needs no database, so bad input is rejected before
        // a round trip to discover whether the post even exists.
        $content  = $this->validator->validate($input);
        $publicId = self::normaliseId($publicId);

        $post = $this->posts->findByPublicId($publicId);
        if ($post === null) {
            return null;
        }

        // The editor sends back the version it opened. A different one means
        // someone saved in between; say so rather than overwrite their work.
        // Omitting it skips this check, but the write itself is still
        // conditional on the version read just above.
        $expected = $input['version'] ?? null;
        if (is_numeric($expected) && (int) $expected !== $post->version()) {
            throw new PostEditConflictException($publicId, $post->version());
        }

        $this->assertCategoriesExist($content);

        $now        = $this->clock->now();
        $wasVisible = $post->isVisibleAt($now);
        $before     = $post->content();

        if (!$before->locale->equals($content->locale)) {
            $this->assertLocaleFreeInGroup($post, $content);
        }

        $content = $this->withUniqueSlug($content, $input, $post);
        $post->replaceContent($content, $this->render($content), $actorId, $now);

        $isVisible = $post->isVisibleAt($now);
        $announce  = $post->needsAnnouncementAt($now);
        if ($announce) {
            $post->markAnnounced($now);
        }

        $after = $post->content();
        $moved = $before->kind !== $after->kind
            || !$before->locale->equals($after->locale)
            || !$before->slug->equals($after->slug);

        $updated = $this->inTransaction('post.update.failed', $publicId, function () use ($post, $before, $after, $moved, $wasVisible, $actorId, $note, $now): bool {
            $id = $post->id()->value();

            if (!$this->posts->update($post)) {
                return false; // deleted between the read and the write
            }

            $this->posts->replaceTags($id, $after->tagValues());
            $this->posts->replaceCategories($id, $after->categoryIds);

            if ($moved) {
                // Readers only ever saw the old address if the post was public.
                if ($wasVisible) {
                    $this->posts->addRedirect($before->kind->value, $before->locale->value(), $before->slug->value(), $id, $now);
                }
                $this->posts->removeRedirect($after->kind->value, $after->locale->value(), $after->slug->value());
            }

            $this->revisionStore->append($id, $after->toInput(), $note, $actorId, $now);
            $this->revisionStore->prune($id, $this->maxRevisions);

            return true;
        });

        if (!$updated) {
            return null;
        }

        match (true) {
            $announce                 => $this->announce(PostPublishedIntegrationEvent::class, $post, $now),
            $wasVisible && $isVisible => $this->announce(PostUpdatedIntegrationEvent::class, $post, $now),
            $wasVisible               => $this->announce(PostUnpublishedIntegrationEvent::class, $post, $now),
            default                   => null,
        };

        return $this->views->view($post, $now);
    }

    public function trash(string $publicId, string $actorId): bool
    {
        $publicId = self::normaliseId($publicId);
        $post     = $this->posts->findByPublicId($publicId);
        if ($post === null || $post->deletedAt() !== null) {
            return false;
        }

        $now        = $this->clock->now();
        $wasVisible = $post->isVisibleAt($now);
        $post->trash($actorId, $now);

        $this->inTransaction('post.trash.failed', $publicId, fn(): bool => $this->posts->update($post));

        if ($wasVisible) {
            $this->announce(PostUnpublishedIntegrationEvent::class, $post, $now);
        }

        return true;
    }

    public function restore(string $publicId, string $actorId): ?PostView
    {
        $publicId = self::normaliseId($publicId);
        $post     = $this->posts->findByPublicId($publicId);
        if ($post === null || $post->deletedAt() === null) {
            return null;
        }

        $now = $this->clock->now();
        $post->restore($actorId, $now);

        $announce = $post->needsAnnouncementAt($now);
        if ($announce) {
            $post->markAnnounced($now);
        }

        $this->inTransaction('post.restore.failed', $publicId, fn(): bool => $this->posts->update($post));

        if ($announce) {
            $this->announce(PostPublishedIntegrationEvent::class, $post, $now);
        }

        return $this->views->view($post, $now);
    }

    public function purge(string $publicId): bool
    {
        $publicId = self::normaliseId($publicId);
        $post     = $this->posts->findByPublicId($publicId);
        if ($post === null) {
            return false;
        }

        $this->inTransaction('post.purge.failed', $publicId, fn(): bool => $this->posts->purge($publicId));
        $this->announce(PostDeletedIntegrationEvent::class, $post, $this->clock->now());

        return true;
    }

    public function duplicate(string $publicId, string $actorId): ?PostView
    {
        $source = $this->posts->findByPublicId(self::normaliseId($publicId));
        if ($source === null) {
            return null;
        }

        $input = $source->content()->toInput();
        $title = $source->content()->title->value();

        $input['title']       = mb_substr($title, 0, PostTitle::MAX_LENGTH - 7) . ' (copy)';
        $input['slug']        = '';
        $input['published']   = false;
        $input['publishedAt'] = null;
        $input['featured']    = false;
        $input['categories']  = $this->existingCategories($input['categories'] ?? []);

        return $this->create($input, $actorId);
    }

    public function revisions(string $publicId, int $limit = 50): array
    {
        return array_map(
            static fn(PostRevision $r): RevisionView => self::revisionView($r, withSnapshot: false),
            $this->revisionStore->forPost(self::normaliseId($publicId), $limit),
        );
    }

    public function revision(string $publicId, int $number): ?RevisionView
    {
        $revision = $this->revisionStore->find(self::normaliseId($publicId), $number);

        return $revision === null ? null : self::revisionView($revision, withSnapshot: true);
    }

    public function restoreRevision(string $publicId, int $number, string $actorId): ?PostView
    {
        $publicId = self::normaliseId($publicId);
        $revision = $this->revisionStore->find($publicId, $number);
        if ($revision === null) {
            return null;
        }

        $input = $revision->snapshot;
        // A category deleted since that revision is dropped, not an error —
        // the editor asked for the old text back, not for a validation fight.
        $input['categories'] = $this->existingCategories($input['categories'] ?? []);

        return $this->update($publicId, $input, $actorId, "Restored revision #{$number}");
    }

    public function previewToken(string $publicId, int $ttlSeconds = 86_400): string
    {
        $publicId = self::normaliseId($publicId);
        if ($this->posts->findByPublicId($publicId) === null) {
            throw new ValidationException(['id' => 'No post has this id.']);
        }

        try {
            return $this->previews->mint($publicId, $this->clock->now(), $ttlSeconds);
        } catch (\RuntimeException $e) {
            throw new ServiceException('posts.preview.disabled', layer: 'service.posts', previous: $e);
        }
    }

    public function findForPreview(string $token, \DateTimeImmutable $now): ?PostView
    {
        $id = $this->previews->verify(trim($token), $now);
        if ($id === null) {
            return null;
        }

        $post = $this->posts->findByPublicId($id);

        return $post === null || $post->deletedAt() !== null ? null : $this->views->view($post, $now);
    }

    // ── public reads ─────────────────────────────────────────────────────

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
    ): PostPage {
        $errors = [];

        $kindFilter      = $this->kindFilter($kind, $errors);
        $localeFilter    = $this->localeFilter($locale, $errors);
        $categoryFilter  = $this->categoryFilter($category, $errors);
        [$from, $before] = $this->monthWindow($year, $month, $errors);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $this->page(
            new PostCriteria(
                now: $now,
                kind: $kindFilter,
                status: PostStatus::Published,
                locale: $localeFilter,
                tag: $this->tagFilter($tag),
                categoryIds: $categoryFilter,
                search: $this->searchFilter($search),
                featuredOnly: $featuredOnly,
                publishedFrom: $from,
                publishedBefore: $before,
                order: PostCriteria::ORDER_PUBLISHED,
                bylineId: $author === null || trim($author) === '' ? null : trim($author),
            ),
            $tag,
            $page,
            $perPage,
        );
    }

    public function findPublished(string $kind, string $slug, \DateTimeImmutable $now, ?string $locale = null): ?PostView
    {
        $post = $this->visiblePost($kind, $slug, $now, $locale);

        return $post === null ? null : $this->views->view($post, $now);
    }

    public function show(string $kind, string $slug, \DateTimeImmutable $now, ?string $locale = null, int $relatedLimit = 3): ?PostDetail
    {
        $post = $this->visiblePost($kind, $slug, $now, $locale);
        if ($post === null) {
            return null;
        }

        $card       = fn(?Post $p): ?PostView => $p === null ? null : $this->views->view($p, $now, withBody: false);
        $adjacent   = $this->posts->adjacentIds($post, $now);
        $relatedIds = $relatedLimit > 0 ? $this->posts->relatedIds($post, $now, min($relatedLimit, 12)) : [];

        // Neighbours and related posts in ONE batch: one row query, one tags
        // query, one categories query, instead of three of each.
        $byId = [];
        $want = array_values(array_unique(array_filter([$adjacent['previous'], $adjacent['next'], ...$relatedIds], 'is_string')));
        foreach ($this->posts->findMany($want) as $loaded) {
            $byId[$loaded->id()->value()] = $loaded;
        }
        $pick    = static fn(?string $id): ?Post => $id === null ? null : ($byId[$id] ?? null);
        $related = array_filter(array_map($pick, $relatedIds));

        $translations = [];
        if ($post->translationGroup() !== null) {
            foreach ($this->posts->translations($post->translationGroup()) as $other) {
                if (!$other->id()->equals($post->id()) && $other->isVisibleAt($now)) {
                    $translations[] = $card($other);
                }
            }
        }

        return new PostDetail(
            post: $this->views->view($post, $now),
            previous: $card($pick($adjacent['previous'])),
            next: $card($pick($adjacent['next'])),
            related: array_values(array_map($card, $related)),
            translations: $translations,
        );
    }

    public function redirectFor(string $kind, string $slug, \DateTimeImmutable $now, ?string $locale = null): ?PostView
    {
        $postKind = PostKind::tryFrom(strtolower(trim($kind)));
        $slug     = strtolower(trim($slug));
        if ($postKind === null || $slug === '') {
            return null;
        }

        $target = $this->posts->findRedirect($postKind, $slug, $locale === null ? null : $this->safeLocale($locale)?->value());
        if ($target === null) {
            return null;
        }

        $post = $this->posts->findByPublicId($target);

        return $post !== null && $post->isVisibleAt($now) ? $this->views->view($post, $now, withBody: false) : null;
    }

    public function popular(\DateTimeImmutable $now, ?string $kind = null, ?string $locale = null, int $limit = 5): array
    {
        $errors     = [];
        $kindFilter = $this->kindFilter($kind, $errors);
        $locale     = $this->localeFilter($locale, $errors);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $posts = $this->posts->search(
            new PostCriteria(now: $now, kind: $kindFilter, status: PostStatus::Published, locale: $locale, order: PostCriteria::ORDER_VIEWS),
            max(1, min($limit, 50)),
            0,
        );

        return array_map(fn(Post $p): PostView => $this->views->view($p, $now, withBody: false), $posts);
    }

    public function tags(\DateTimeImmutable $now, ?string $kind = null, int $limit = 50, ?string $locale = null): array
    {
        $errors     = [];
        $kindFilter = $this->kindFilter($kind, $errors);
        $locale     = $this->localeFilter($locale, $errors);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $this->posts->tagCounts($now, $kindFilter, $locale, $limit);
    }

    public function archive(\DateTimeImmutable $now, ?string $kind = null, ?string $locale = null): array
    {
        $errors     = [];
        $kindFilter = $this->kindFilter($kind, $errors);
        $locale     = $this->localeFilter($locale, $errors);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $months = [];
        foreach ($this->posts->visibleDates($now, $kindFilter, $locale) as $date) {
            $key          = substr($date, 0, 7);
            $months[$key] = ($months[$key] ?? 0) + 1;
        }

        $out = [];
        foreach ($months as $key => $count) {
            $out[] = ['year' => (int) substr((string) $key, 0, 4), 'month' => (int) substr((string) $key, 5, 2), 'count' => $count];
        }

        return $out;
    }

    public function recordView(string $publicId, \DateTimeImmutable $now): bool
    {
        return $this->posts->incrementViews(self::normaliseId($publicId), $now);
    }

    // ── ops ──────────────────────────────────────────────────────────────

    public function publishDue(\DateTimeImmutable $now, int $limit = 100): int
    {
        $announced = 0;

        foreach ($this->posts->dueForAnnouncement($now, $limit) as $post) {
            // Claim first: a second scheduler racing over the same post loses
            // the compare-and-set and sends nothing.
            if ($this->posts->claimAnnouncement($post->id()->value(), $now)) {
                $this->announce(PostPublishedIntegrationEvent::class, $post, $now);
                $announced++;
            }
        }

        return $announced;
    }

    public function emptyTrash(\DateTimeImmutable $before, int $limit = 200): int
    {
        $purged = 0;
        foreach ($this->posts->trashedBefore($before, $limit) as $id) {
            if ($this->purge($id)) {
                $purged++;
            }
        }

        return $purged;
    }

    public function rerender(int $limit = 100, ?string $after = null): array
    {
        $limit = max(1, min($limit, 1000));
        $ids   = $this->posts->idsAfter($after === null ? null : self::normaliseId($after), $limit);

        foreach ($ids as $id) {
            $post = $this->posts->findByPublicId($id);
            if ($post === null) {
                continue;
            }

            $post->refreshRendering($this->render($post->content()));
            $this->inTransaction('post.rerender.failed', $id, fn(): bool => $this->posts->update($post));
        }

        return ['processed' => count($ids), 'last' => count($ids) < $limit ? null : $ids[count($ids) - 1]];
    }

    // ── internals ────────────────────────────────────────────────────────

    private function visiblePost(string $kind, string $slug, \DateTimeImmutable $now, ?string $locale): ?Post
    {
        $postKind = PostKind::tryFrom(strtolower(trim($kind)));
        $slug     = strtolower(trim($slug));
        if ($postKind === null || $slug === '') {
            return null;
        }

        $localeFilter = $locale === null ? null : $this->safeLocale($locale)?->value();
        if ($locale !== null && $localeFilter === null) {
            return null; // a malformed locale is a 404, not a 500
        }

        return $this->posts->findVisible($postKind, $slug, $now, $localeFilter);
    }

    private function render(PostContent $content): RenderedBody
    {
        return $content->body === null
            ? RenderedBody::empty()
            : $this->renderer->render($content->body->value(), $content->bodyFormat);
    }

    /**
     * Run `$work` in one transaction. Anything it throws rolls back and
     * becomes a ServiceException carrying `$code` — never a half-written post.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    private function inTransaction(string $code, string $publicId, \Closure $work): mixed
    {
        $this->transaction->begin();
        try {
            $result = $work();
            $this->transaction->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            if ($e instanceof OptimisticLockException) {
                throw new PostEditConflictException($publicId, (int) ($e->context['current'] ?? 0), $e);
            }
            throw new ServiceException($code, layer: 'service.posts', context: ['publicId' => $publicId], previous: $e);
        }
    }

    /**
     * A translation group holds one post per language. create() checks this
     * through translationOf; a later locale change must too, or a group ends
     * up with two Spanish versions and no way to tell which is canonical.
     */
    private function assertLocaleFreeInGroup(Post $post, PostContent $content): void
    {
        if ($post->translationGroup() === null) {
            return;
        }

        foreach ($this->posts->translations($post->translationGroup()) as $member) {
            if (!$member->id()->equals($post->id()) && $member->content()->locale->equals($content->locale)) {
                $label = $content->locale->isNone() ? 'the default language' : "'{$content->locale->value()}'";
                throw new ValidationException(['locale' => "This post already has a version in {$label}."]);
            }
        }
    }

    /** @param class-string<PostIntegrationEvent> $event */
    private function announce(string $event, Post $post, \DateTimeImmutable $now): void
    {
        $c = $post->content();

        $this->events->publish(new $event(
            postId: $post->id()->value(),
            kind: $c->kind->value,
            locale: $c->locale->value(),
            slug: $c->slug->value(),
            path: $this->views->path($post),
            publishedAt: $c->publishedAt?->format(\DateTimeInterface::ATOM),
            occurredAt: $now->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
        ));
    }

    /**
     * Resolve `translationOf` for a new post: the group it joins, and the
     * source post when the source must be stamped with the group too (it had
     * none yet). Rejects a locale the group already has.
     *
     * @param array<string, mixed> $input
     * @return array{0: ?string, 1: ?Post}
     */
    private function translationGroupFor(array $input, PostContent $content): array
    {
        $raw = $input['translationOf'] ?? null;
        if (!is_scalar($raw) || trim((string) $raw) === '') {
            return [null, null];
        }

        $source = $this->posts->findByPublicId(self::normaliseId((string) $raw));
        if ($source === null || $source->deletedAt() !== null) {
            throw new ValidationException(['translationOf' => 'No post has this id.']);
        }

        $group   = $source->translationGroup() ?? $source->id()->value();
        $members = $source->translationGroup() === null ? [$source] : $this->posts->translations($group);

        foreach ($members as $member) {
            if ($member->content()->locale->equals($content->locale)) {
                $label = $content->locale->isNone() ? 'the default language' : "'{$content->locale->value()}'";
                throw new ValidationException(['locale' => "This post already has a version in {$label}."]);
            }
        }

        if ($source->translationGroup() !== null) {
            return [$group, null];
        }

        $source->joinTranslationGroup($group);

        return [$group, $source];
    }

    private function assertCategoriesExist(PostContent $content): void
    {
        $tree   = $this->views->tree();
        $errors = [];

        foreach ($content->categoryIds as $i => $id) {
            if (!$tree->has($id)) {
                $errors["categories.{$i}"] = 'No category has this id.';
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    /** @return list<string> */
    private function existingCategories(mixed $ids): array
    {
        $tree = $this->views->tree();

        return array_values(array_filter(
            is_array($ids) ? array_map('strval', $ids) : [],
            static fn(string $id): bool => $tree->has($id),
        ));
    }

    /**
     * Settle the slug against the posts already stored.
     *
     *   - An editor-supplied slug is used as given; a clash is a field error,
     *     never a silent `-2` — they typed that URL for a reason.
     *   - Otherwise an existing post keeps its slug, and a new one derives it
     *     from the title. Either may be suffixed (`-2`, `-3`, …) to stay unique
     *     — the existing one only when its kind or locale changed into a clash.
     *
     * The unique (kind, locale, slug) index is the real guarantee; this check
     * turns the common collision into a readable error instead of a failed
     * insert. Two editors racing for one slug still get a ServiceException.
     *
     * @param array<string, mixed> $input
     */
    private function withUniqueSlug(PostContent $content, array $input, ?Post $existing): PostContent
    {
        $exceptId = $existing?->id()->value();

        if (PostInputValidator::givesSlug($input)) {
            if ($this->posts->slugTaken($content->kind, $content->locale->value(), $content->slug->value(), $exceptId)) {
                throw new ValidationException(['slug' => 'Another ' . $content->kind->value . ' already uses this address.']);
            }

            return $content;
        }

        $base = $existing?->content()->slug ?? $content->slug;

        return $content->with(slug: $this->freeSlug($base, $content->kind, $content->locale, $exceptId));
    }

    private function freeSlug(PostSlug $base, PostKind $kind, PostLocale $locale, ?string $exceptId): PostSlug
    {
        $candidate = $base;
        for ($n = 2; $n <= self::SLUG_ATTEMPTS + 1; $n++) {
            if (!$this->posts->slugTaken($kind, $locale->value(), $candidate->value(), $exceptId)) {
                return $candidate;
            }
            $candidate = $base->withSuffix($n);
        }

        return $base->withSuffix(random_int(1000, 999999));
    }

    /** One page of a listing, plus the total a pager needs. */
    private function page(PostCriteria $criteria, ?string $rawTag, int $page, int $perPage): PostPage
    {
        $page    = max(1, $page);
        $perPage = max(1, min($perPage, PostRepository::MAX_PER_PAGE));

        // A tag that normalises to nothing ("!!!") can match no post. Answer
        // with an empty page rather than silently dropping the filter and
        // returning EVERY post, which is what a null tag would mean.
        if ($rawTag !== null && trim($rawTag) !== '' && $criteria->tag === null) {
            return new PostPage([], 0, $page, $perPage);
        }

        $total = $this->posts->count($criteria);
        $posts = $total === 0 ? [] : $this->posts->search($criteria, $perPage, ($page - 1) * $perPage);

        return new PostPage(
            items: array_map(fn(Post $post): PostView => $this->views->view($post, $criteria->now, withBody: false), $posts),
            total: $total,
            page: $page,
            perPage: $perPage,
        );
    }

    /** @param array<string, string> $errors */
    private function kindFilter(?string $kind, array &$errors): ?PostKind
    {
        if ($kind === null || trim($kind) === '') {
            return null;
        }

        $postKind = PostKind::tryFrom(strtolower(trim($kind)));
        if ($postKind === null) {
            $errors['kind'] = 'Kind must be one of: ' . implode(', ', PostKind::values()) . '.';
        }

        return $postKind;
    }

    /**
     * null = every locale. An explicit empty string is NOT "every locale" —
     * it is the default locale, which is a real stream on its own.
     *
     * @param array<string, string> $errors
     */
    private function localeFilter(?string $locale, array &$errors): ?string
    {
        if ($locale === null) {
            return null;
        }

        try {
            return PostLocale::fromNullable($locale)->value();
        } catch (\DomainException $e) {
            $errors['locale'] = $e->getMessage();

            return null;
        }
    }

    private function safeLocale(string $locale): ?PostLocale
    {
        try {
            return PostLocale::fromNullable($locale);
        } catch (\DomainException) {
            return null;
        }
    }

    /**
     * A category SLUG → its id plus every descendant's.
     *
     * @param array<string, string> $errors
     * @return list<string>|null
     */
    private function categoryFilter(?string $slug, array &$errors): ?array
    {
        if ($slug === null || trim($slug) === '') {
            return null;
        }

        $category = $this->views->tree()->findBySlug($slug);
        if ($category === null) {
            $errors['category'] = 'No category has this slug.';

            return null;
        }

        return $this->views->tree()->selfAndDescendants($category->id()->value());
    }

    /**
     * @param array<string, string> $errors
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    private function monthWindow(?int $year, ?int $month, array &$errors): array
    {
        if ($year === null) {
            if ($month !== null) {
                $errors['month'] = 'A month needs a year.';
            }

            return [null, null];
        }

        if ($year < 1970 || $year > 9999) {
            $errors['year'] = 'Year must be between 1970 and 9999.';

            return [null, null];
        }

        if ($month !== null && ($month < 1 || $month > 12)) {
            $errors['month'] = 'Month must be between 1 and 12.';

            return [null, null];
        }

        $utc   = new \DateTimeZone('UTC');
        $start = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month ?? 1), $utc);

        return [$start, $start->modify($month === null ? '+1 year' : '+1 month')];
    }

    private function tagFilter(?string $tag): ?string
    {
        if ($tag === null || trim($tag) === '') {
            return null;
        }

        try {
            return PostTag::fromString($tag)->value();
        } catch (\DomainException) {
            return null;
        }
    }

    private function searchFilter(?string $search): ?string
    {
        $trimmed = trim((string) $search);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, self::SEARCH_MAX);
    }

    private static function revisionView(PostRevision $revision, bool $withSnapshot): RevisionView
    {
        return new RevisionView(
            number: $revision->number,
            title: (string) ($revision->snapshot['title'] ?? ''),
            note: $revision->note,
            createdBy: $revision->createdBy,
            createdAt: $revision->createdAt->format(\DateTimeInterface::ATOM),
            snapshot: $withSnapshot ? $revision->snapshot : null,
        );
    }

    /**
     * Ids are upper-cased HERE, before they reach SQL, so a lookup behaves the
     * same on every driver: LetMigrate's MySQL grammar does not emit column
     * collations, so MySQL compares `public_id` case-insensitively while
     * PostgreSQL and SQLite compare exactly. A stored id is always upper-case.
     */
    private static function normaliseId(string $publicId): string
    {
        return strtoupper(trim($publicId));
    }
}
