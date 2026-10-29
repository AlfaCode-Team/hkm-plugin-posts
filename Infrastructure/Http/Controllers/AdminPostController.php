<?php

declare(strict_types=1);

namespace Plugins\Posts\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Posts\API\DTO\PostView;
use Plugins\Posts\API\DTO\RevisionView;
use Plugins\Posts\Application\Services\PostEditorialService;
use Plugins\Posts\Infrastructure\Http\HttpContext;

/**
 * The admin JSON API, behind the `posts.editor` route filter. Every action is
 * a straight hand-off to PostEditorialService, which decides what this user
 * may do to this post; nothing here is cacheable.
 */
final class AdminPostController
{
    public function __construct(
        private readonly PostEditorialService $editorial,
        private readonly HttpContext $http,
    ) {
    }

    /** GET /api/admin/posts/me — what the UI may offer this user. */
    public function me(Request $request): Response
    {
        return self::ok(['data' => $this->editorial->capabilities()]);
    }

    /** GET /api/admin/posts?kind&status&tag&q&locale&category&author&order&page&perPage */
    public function index(Request $request): Response
    {
        $page = $this->editorial->list($request->queryAll())->toArray();

        return self::ok(['data' => $page['items'], 'meta' => array_diff_key($page, ['items' => 1])]);
    }

    /** GET /api/admin/posts/counts?kind&tag&q&locale&category&author — one number per status tab. */
    public function counts(Request $request): Response
    {
        return self::ok(['data' => $this->editorial->statusCounts($request->queryAll())]);
    }

    /** GET /api/admin/posts/stats */
    public function stats(Request $request): Response
    {
        return self::ok(['data' => $this->editorial->stats()]);
    }

    /** GET /api/admin/posts/slug?title&kind&locale&except */
    public function slug(Request $request): Response
    {
        return self::ok(['data' => ['slug' => $this->editorial->suggestSlug($request->queryAll())]]);
    }

    /** POST /api/admin/posts/render {body, bodyFormat} — live preview, nothing saved. */
    public function render(Request $request): Response
    {
        return self::ok(['data' => $this->editorial->renderPreview($request->input('body'), $request->input('bodyFormat'))]);
    }

    /** POST /api/admin/posts/bulk {action, ids[]} */
    public function bulk(Request $request): Response
    {
        $ids = $request->input('ids');

        return self::ok(['data' => $this->editorial->bulk((string) $request->input('action', ''), is_array($ids) ? $ids : [])]);
    }

    /** POST /api/admin/posts */
    public function store(Request $request): Response
    {
        return Response::created(['data' => $this->editorial->create($request->all())->toArray()])->withHeader('Cache-Control', 'no-store');
    }

    /** GET /api/admin/posts/{id} */
    public function show(Request $request, string $id): Response
    {
        return self::view($this->editorial->find($id));
    }

    /** PUT /api/admin/posts/{id} — full replace; optional `revisionNote`. */
    public function update(Request $request, string $id): Response
    {
        $note = $request->input('revisionNote');

        return self::view($this->editorial->update($id, $request->all(), is_scalar($note) && trim((string) $note) !== '' ? mb_substr(trim((string) $note), 0, 200) : null));
    }

    /** DELETE /api/admin/posts/{id} — to the trash. */
    public function destroy(Request $request, string $id): Response
    {
        return $this->editorial->trash($id) ? Response::noContent() : Response::notFound('No post has this id, or it is already in the trash.');
    }

    /** POST /api/admin/posts/{id}/restore */
    public function restore(Request $request, string $id): Response
    {
        return self::view($this->editorial->restore($id), 'No trashed post has this id.');
    }

    /** DELETE /api/admin/posts/{id}/permanent */
    public function purge(Request $request, string $id): Response
    {
        return $this->editorial->purge($id) ? Response::noContent() : Response::notFound('No post has this id.');
    }

    /** POST /api/admin/posts/{id}/duplicate */
    public function duplicate(Request $request, string $id): Response
    {
        $copy = $this->editorial->duplicate($id);

        return $copy === null ? Response::notFound('No post has this id.') : Response::created(['data' => $copy->toArray()]);
    }

    /** GET /api/admin/posts/{id}/revisions */
    public function revisions(Request $request, string $id): Response
    {
        $revisions = $this->editorial->revisions($id);

        return $revisions === null
            ? Response::notFound('No post has this id.')
            : self::ok(['data' => array_map(static fn(RevisionView $r): array => $r->toArray(), $revisions)]);
    }

    /** GET /api/admin/posts/{id}/revisions/{number} */
    public function revision(Request $request, string $id, string $number): Response
    {
        $revision = $this->editorial->revision($id, (int) $number);

        return $revision === null ? Response::notFound('No such revision.') : self::ok(['data' => $revision->toArray()]);
    }

    /** POST /api/admin/posts/{id}/revisions/{number}/restore */
    public function restoreRevision(Request $request, string $id, string $number): Response
    {
        return self::view($this->editorial->restoreRevision($id, (int) $number), 'No such revision.');
    }

    /** GET /api/admin/posts/{id}/translations */
    public function translations(Request $request, string $id): Response
    {
        $versions = $this->editorial->translations($id);

        return $versions === null
            ? Response::notFound('No post has this id.')
            : self::ok(['data' => array_map(static fn(PostView $v): array => $v->toArray(includeBody: false), $versions)]);
    }

    /** POST /api/admin/posts/{id}/preview-link {ttl?} — a shareable link to read a draft. */
    public function previewLink(Request $request, string $id): Response
    {
        $ttl  = max(60, min((int) ($request->input('ttl') ?? 86_400), 604_800));
        $link = $this->editorial->previewToken($id, $ttl);

        return $link === null
            ? Response::notFound('No post has this id.')
            : self::ok(['data' => $link + ['url' => $request->site()->to('api/posts/preview/' . $link['token'])]]);
    }

    private static function view(?PostView $post, string $missing = 'No post has this id.'): Response
    {
        return $post === null ? Response::notFound($missing) : self::ok(['data' => $post->toArray()]);
    }

    /** @param array<string, mixed> $body */
    private static function ok(array $body): Response
    {
        return Response::json($body)->withHeader('Cache-Control', 'no-store');
    }
}
