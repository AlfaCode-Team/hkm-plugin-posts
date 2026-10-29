<?php

declare(strict_types=1);

namespace Plugins\Posts\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Pageflow\Http\PageflowResponder;
use Plugins\Posts\API\DTO\CategoryView;
use Plugins\Posts\API\DTO\PostView;
use Plugins\Posts\Application\Services\PostEditorialService;
use Plugins\Posts\Infrastructure\Http\HttpContext;

/**
 * Pageflow (SPA) controller — the server half of ui/admin/Pages/Posts/*.tsx.
 *
 * Behind the SAME `posts.editor` filter as the JSON admin API (module.json),
 * so only a signed-in editor ever reaches these pages. `PostEditorialService`
 * is this module's OWN internal binding (see Provider::register) — resolvable
 * here because this controller lives in the SAME module scope, exactly like
 * `AdminPostController`/`AdminCategoryController` resolve it; no cross-module
 * access is involved.
 *
 * Everything these pages need AFTER the initial load (filtering, pagination,
 * bulk actions, saves, revisions, preview links, category writes) goes
 * through the admin JSON API directly (`ui/admin/lib/api.ts`) — this
 * controller only renders the FIRST paint, so a reload lands on real data
 * instead of an empty shell.
 */
final class PostFlowController
{
    public function __construct(
        private readonly PageflowResponder $pageflow,
        private readonly PostEditorialService $editorial,
        private readonly HttpContext $http,
    ) {
    }

    /** GET /admin/posts — component "Posts/Index". */
    public function index(Request $request): Response
    {
        $capabilities = $this->editorial->capabilities();
        $kind         = $capabilities['kinds'][0] ?? 'blog';
        $query        = ['kind' => $kind, 'page' => 1, 'perPage' => 20, 'order' => 'created'];
        $page         = $this->editorial->list($query)->toArray();

        return $this->pageflow->render($request, 'Posts/Index', 'admin', [
            'capabilities' => $capabilities,
            'categories'   => array_map(static fn(CategoryView $c): array => $c->toArray(), $this->editorial->categoryTree($this->http->now())),
            'initialQuery' => ['kind' => $kind, 'status' => 'all', 'page' => 1, 'perPage' => 20, 'order' => 'created'],
            'initialPage'  => ['data' => $page['items'], 'meta' => array_diff_key($page, ['items' => 1])],
        ]);
    }

    /** GET /admin/posts/create — component "Posts/Editor" with no post loaded. */
    public function create(Request $request): Response
    {
        $capabilities = $this->editorial->capabilities();

        return $this->pageflow->render($request, 'Posts/Editor', 'admin', [
            'postId'       => null,
            'post'         => null,
            'capabilities' => $capabilities,
            'categories'   => array_map(static fn(CategoryView $c): array => $c->toArray(), $this->editorial->categoryTree($this->http->now())),
        ]);
    }

    /** GET /admin/posts/{id}/edit — component "Posts/Editor". */
    public function edit(Request $request, string $id): Response
    {
        $post = $this->editorial->find($id);
        if ($post === null) {
            return Response::notFound('No post has this id.');
        }

        return $this->pageflow->render($request, 'Posts/Editor', 'admin', [
            'postId'       => $id,
            'post'         => $post->toArray(),
            'capabilities' => $this->editorial->capabilities(),
            'categories'   => array_map(static fn(CategoryView $c): array => $c->toArray(), $this->editorial->categoryTree($this->http->now())),
        ]);
    }

    /** GET /admin/posts/categories — component "Posts/Categories". */
    public function categories(Request $request): Response
    {
        return $this->pageflow->render($request, 'Posts/Categories', 'admin', [
            'capabilities' => $this->editorial->capabilities(),
            'categories'   => array_map(static fn(CategoryView $c): array => $c->toArray(), $this->editorial->categoryTree($this->http->now())),
        ]);
    }
}
