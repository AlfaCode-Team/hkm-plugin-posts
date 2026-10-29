<?php

declare(strict_types=1);

namespace Plugins\Posts\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Posts\API\Contracts\PostCategoryServiceContract;
use Plugins\Posts\API\Contracts\PostServiceContract;
use Plugins\Posts\API\DTO\CategoryView;
use Plugins\Posts\API\DTO\PostPage;
use Plugins\Posts\API\DTO\PostView;
use Plugins\Posts\API\DTO\RevisionView;
use Plugins\Posts\Application\Contracts\BodyRendererContract;
use Plugins\Posts\Domain\ValueObjects\BodyFormat;
use Plugins\Posts\Domain\ValueObjects\PostBody;

/**
 * PostEditorialService — the admin API's authorization, in the service layer
 * where the access rules put it. The published contracts trust their caller;
 * this is the caller the plugin's own admin routes use, and it decides what
 * the signed-in user may do before delegating.
 *
 * Three permissions, each configurable (POSTS_*_PERMISSION), modelled on the
 * contributor / author / editor split every newsroom ends up needing:
 *
 *   write    create posts, edit and trash their OWN posts while they are
 *            drafts. Cannot publish, cannot touch a published post.
 *   publish  (with write) also publish, schedule and unpublish their own.
 *   manage   everything: anyone's posts, categories, purge, empty the trash.
 *
 * "Own" = created by the user or bylined to them (`authorId`). A non-manager
 * cannot byline someone else, and sees only their own posts in the list.
 *
 * Denials are SecurityException with code 403, which ErrorStage turns into a
 * 403 response. Request-scoped: holds the request's Identity.
 */
final class PostEditorialService
{
    public function __construct(
        private readonly PostServiceContract $posts,
        private readonly PostCategoryServiceContract $categories,
        private readonly Identity $identity,
        private readonly string $writePermission = 'posts:write',
        private readonly string $publishPermission = 'posts:publish',
        private readonly string $managePermission = 'posts:manage',
        private readonly ?BodyRendererContract $renderer = null,
    ) {
    }

    /** May this user use the admin API at all? (What the route filter checks.) */
    public static function isEditor(?Identity $identity, string $write, string $publish, string $manage): bool
    {
        if ($identity === null || $identity->isGuest()) {
            return false;
        }

        foreach ([$write, $publish, $manage] as $permission) {
            if ($permission !== '' && $identity->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{userId: string, canWrite: bool, canPublish: bool, canManage: bool, kinds: list<string>} For the admin UI to hide what it cannot do. */
    public function capabilities(): array
    {
        return [
            'kinds'      => $this->posts->kinds(),
            'userId'     => $this->identity->userId,
            // The signed-in author's display name, so the editor can show the
            // auto-assigned byline ("Written by …") without a second lookup —
            // the same value create() falls back to when authorName is blank.
            'userName'   => $this->identity->fullName !== ''
                ? $this->identity->fullName
                : $this->identity->username,
            'canWrite'   => $this->can($this->writePermission) || $this->isManager(),
            'canPublish' => $this->can($this->publishPermission) || $this->isManager(),
            'canManage'  => $this->isManager(),
        ];
    }

    // ── posts ────────────────────────────────────────────────────────────

    public function list(array $query): PostPage
    {
        return $this->posts->list(
            kind: self::str($query, 'kind'),
            status: self::str($query, 'status'),
            tag: self::str($query, 'tag'),
            search: self::str($query, 'q'),
            page: max(1, (int) ($query['page'] ?? 1)),
            perPage: max(1, (int) ($query['perPage'] ?? 20)),
            locale: array_key_exists('locale', $query) ? (string) $query['locale'] : null,
            category: self::str($query, 'category'),
            // A non-manager's list is their own posts, whatever they ask for.
            authorId: $this->isManager() ? self::str($query, 'author') : $this->identity->userId,
            order: self::str($query, 'order') ?? 'created',
        );
    }

    /**
     * The status tab counts for the same filters as list(), scoped the same
     * way: a non-manager counts only their own posts.
     *
     * @param array<string, mixed> $query
     * @return array{draft: int, scheduled: int, published: int, trash: int}
     */
    public function statusCounts(array $query): array
    {
        return $this->posts->statusCounts(
            kind: self::str($query, 'kind'),
            tag: self::str($query, 'tag'),
            search: self::str($query, 'q'),
            locale: array_key_exists('locale', $query) ? (string) $query['locale'] : null,
            category: self::str($query, 'category'),
            authorId: $this->isManager() ? self::str($query, 'author') : $this->identity->userId,
        );
    }

    public function find(string $id): ?PostView
    {
        $post = $this->posts->find($id);
        if ($post !== null) {
            $this->assertOwnsOrManages($post);
        }

        return $post;
    }

    public function create(array $input): PostView
    {
        $this->assertCan($this->writePermission);
        $input = $this->bylined($input);
        $this->assertMayPublish($input, null);

        return $this->posts->create($input, $this->identity->userId);
    }

    public function update(string $id, array $input, ?string $note = null): ?PostView
    {
        $current = $this->posts->find($id);
        if ($current === null) {
            return null;
        }

        $this->assertOwnsOrManages($current);
        $input = $this->bylined($input, $current);
        $this->assertMayPublish($input, $current);

        return $this->posts->update($id, $input, $this->identity->userId, $note);
    }

    public function trash(string $id): bool
    {
        $current = $this->posts->find($id);
        if ($current === null) {
            return false;
        }

        $this->assertOwnsOrManages($current);
        if ($current->isPublic()) {
            $this->assertCan($this->publishPermission, 'Taking down a published post needs the publish permission.');
        }

        return $this->posts->trash($id, $this->identity->userId);
    }

    public function restore(string $id): ?PostView
    {
        $current = $this->posts->find($id);
        if ($current === null) {
            return null;
        }

        $this->assertOwnsOrManages($current);
        if ($current->published) {
            $this->assertCan($this->publishPermission, 'Restoring a published post puts it back online, which needs the publish permission.');
        }

        return $this->posts->restore($id, $this->identity->userId);
    }

    public function purge(string $id): bool
    {
        $this->assertManager();

        return $this->posts->purge($id);
    }

    public function duplicate(string $id): ?PostView
    {
        $current = $this->posts->find($id);
        if ($current === null) {
            return null;
        }

        $this->assertOwnsOrManages($current);

        return $this->posts->duplicate($id, $this->identity->userId);
    }

    /** @return list<RevisionView>|null */
    public function revisions(string $id): ?array
    {
        $current = $this->posts->find($id);
        if ($current === null) {
            return null;
        }

        $this->assertOwnsOrManages($current);

        return $this->posts->revisions($id);
    }

    public function revision(string $id, int $number): ?RevisionView
    {
        $current = $this->posts->find($id);
        if ($current === null) {
            return null;
        }

        $this->assertOwnsOrManages($current);

        return $this->posts->revision($id, $number);
    }

    public function restoreRevision(string $id, int $number): ?PostView
    {
        $current  = $this->posts->find($id);
        $revision = $current === null ? null : $this->posts->revision($id, $number);
        if ($current === null || $revision === null) {
            return null;
        }

        $this->assertOwnsOrManages($current);
        $this->assertMayPublish($revision->snapshot ?? [], $current);

        return $this->posts->restoreRevision($id, $number, $this->identity->userId);
    }

    /** @return list<PostView>|null */
    public function translations(string $id): ?array
    {
        $current = $this->posts->find($id);
        if ($current === null) {
            return null;
        }

        $this->assertOwnsOrManages($current);

        return $this->posts->translations($id);
    }

    /** @return array{token: string, expiresIn: int}|null */
    public function previewToken(string $id, int $ttl): ?array
    {
        $current = $this->posts->find($id);
        if ($current === null) {
            return null;
        }

        $this->assertOwnsOrManages($current);

        return ['token' => $this->posts->previewToken($id, $ttl), 'expiresIn' => $ttl];
    }

    public function suggestSlug(array $query): string
    {
        $this->assertCan($this->writePermission);

        return $this->posts->suggestSlug(
            (string) ($query['title'] ?? ''),
            (string) ($query['kind'] ?? 'blog'),
            (string) ($query['locale'] ?? ''),
            self::str($query, 'except'),
        );
    }

    /**
     * Render a body exactly as saving it would — the editor's live preview.
     * Nothing is stored.
     *
     * @return array{html: string, toc: list<array{level: int, id: string, text: string}>}
     */
    public function renderPreview(mixed $body, mixed $format): array
    {
        $this->assertCan($this->writePermission);

        if ($this->renderer === null) {
            throw new ServiceException('posts.render.unavailable', layer: 'service.posts');
        }

        try {
            $bodyFormat = BodyFormat::fromNullable(is_scalar($format) ? (string) $format : null);
            $text       = PostBody::fromNullable(is_scalar($body) ? (string) $body : null);
        } catch (\DomainException $e) {
            throw new ValidationException(['body' => $e->getMessage()]);
        }

        $rendered = $text === null ? null : $this->renderer->render($text->value(), $bodyFormat);

        return ['html' => $rendered?->html ?? '', 'toc' => $rendered?->toc ?? []];
    }

    /** @return array<string, array<string, int>> */
    public function stats(): array
    {
        $this->assertManager();

        return $this->posts->stats();
    }

    /**
     * Apply one action to many posts. Each id is authorized and applied on its
     * own, so one forbidden or missing post does not abort the rest — the
     * result says what happened to each.
     *
     * @param list<string> $ids
     * @return array<string, string> id => 'ok' | 'not_found' | 'forbidden' | 'invalid'
     */
    public function bulk(string $action, array $ids): array
    {
        $results = [];

        foreach (array_slice(array_values(array_unique(array_map('strval', $ids))), 0, 200) as $id) {
            try {
                $ok = match ($action) {
                    'trash'     => $this->trash($id),
                    'restore'   => $this->restore($id) !== null,
                    'purge'     => $this->purge($id),
                    'publish'   => $this->setPublished($id, true),
                    'unpublish' => $this->setPublished($id, false),
                    default     => throw new \InvalidArgumentException('Unknown action.'),
                };
                $results[$id] = $ok ? 'ok' : 'not_found';
            } catch (SecurityException) {
                $results[$id] = 'forbidden';
            } catch (\InvalidArgumentException) {
                throw new ValidationException(['action' => 'Action must be one of: trash, restore, purge, publish, unpublish.']);
            } catch (ValidationException) {
                $results[$id] = 'invalid';
            }
        }

        return $results;
    }

    // ── categories ───────────────────────────────────────────────────────

    /** @return list<CategoryView> */
    public function categoryTree(\DateTimeImmutable $now): array
    {
        return $this->categories->tree($now);
    }

    public function createCategory(array $input): CategoryView
    {
        $this->assertManager();

        return $this->categories->create($input);
    }

    public function updateCategory(string $id, array $input): ?CategoryView
    {
        $this->assertManager();

        return $this->categories->update($id, $input);
    }

    public function deleteCategory(string $id): bool
    {
        $this->assertManager();

        return $this->categories->delete($id);
    }

    // ── policy ───────────────────────────────────────────────────────────

    private function setPublished(string $id, bool $published): bool
    {
        $current = $this->posts->find($id);
        if ($current === null) {
            return false;
        }

        $input = ['published' => $published] + $current->toInput();

        return $this->update($id, $input, $published ? 'Published' : 'Unpublished') !== null;
    }

    /**
     * Fill in the byline from the signed-in user when none is given, and stop
     * a non-manager from bylining anyone else.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function bylined(array $input, ?PostView $current = null): array
    {
        $given = is_scalar($input['authorId'] ?? null) ? trim((string) $input['authorId']) : '';

        if ($given === '') {
            $input['authorId'] = $current?->authorId ?? $this->identity->userId;
            if (!is_scalar($input['authorName'] ?? null) || trim((string) $input['authorName']) === '') {
                $input['authorName'] = $current?->authorName ?? ($this->identity->fullName !== '' ? $this->identity->fullName : ($this->identity->username !== '' ? $this->identity->username : null));
            }
        } elseif (!$this->isManager() && $given !== $this->identity->userId && $given !== $current?->authorId) {
            throw $this->deny('Only an editor with the manage permission can byline someone else.');
        }

        return $input;
    }

    /**
     * Publishing — or editing what is already published or scheduled — needs
     * the publish permission (or manage).
     *
     * @param array<string, mixed> $input
     */
    private function assertMayPublish(array $input, ?PostView $current): void
    {
        if ($this->isManager() || $this->can($this->publishPermission)) {
            return;
        }

        $wantsPublished = PostInputValidator::toBool($input['published'] ?? false);
        if ($wantsPublished || ($current !== null && $current->published)) {
            throw $this->deny('Publishing, or editing a published or scheduled post, needs the publish permission.');
        }
    }

    private function assertOwnsOrManages(PostView $post): void
    {
        if ($this->isManager()) {
            return;
        }

        $this->assertCan($this->writePermission);

        $me = $this->identity->userId;
        if ($post->createdBy !== $me && $post->authorId !== $me) {
            throw $this->deny('You can only work on your own posts.');
        }
    }

    private function assertManager(): void
    {
        if (!$this->isManager()) {
            throw $this->deny('This needs the manage permission.');
        }
    }

    private function assertCan(string $permission, string $message = 'You do not have permission to do this.'): void
    {
        if (!$this->isManager() && !$this->can($permission)) {
            throw $this->deny($message);
        }
    }

    private function isManager(): bool
    {
        return $this->can($this->managePermission);
    }

    private function can(string $permission): bool
    {
        return $permission !== '' && !$this->identity->isGuest() && $this->identity->hasPermission($permission);
    }

    private function deny(string $message): SecurityException
    {
        return new SecurityException($message, layer: 'posts.policy', code: 403);
    }

    /** @param array<string, mixed> $query */
    private static function str(array $query, string $key): ?string
    {
        $value = $query[$key] ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
