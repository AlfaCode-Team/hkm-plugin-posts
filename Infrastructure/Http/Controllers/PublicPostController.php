<?php

declare(strict_types=1);

namespace Plugins\Posts\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Posts\API\Contracts\PostCategoryServiceContract;
use Plugins\Posts\API\Contracts\PostServiceContract;
use Plugins\Posts\API\DTO\CategoryView;
use Plugins\Posts\API\DTO\PostView;
use Plugins\Posts\Application\Services\PostViewCounter;
use Plugins\Posts\Infrastructure\Http\HttpContext;

/**
 * The public JSON API — what a site's front end (or a mobile app) reads.
 * Public posts only; every response is cacheable for a minute except the
 * view beacon and the preview, which must never be cached.
 */
final class PublicPostController
{
    private const string CACHE = 'public, max-age=60';

    public function __construct(
        private readonly PostServiceContract $posts,
        private readonly PostCategoryServiceContract $categories,
        private readonly PostViewCounter $views,
        private readonly HttpContext $http,
    ) {
    }

    /** GET /api/posts?kind&tag&category&q&featured&locale&year&month&author&page&perPage */
    public function index(Request $request): Response
    {
        $page = $this->posts->published(
            now: $this->http->now(),
            kind: HttpContext::q($request, 'kind'),
            tag: HttpContext::q($request, 'tag'),
            search: HttpContext::q($request, 'q'),
            featuredOnly: $request->boolean('featured'),
            page: HttpContext::qInt($request, 'page') ?? 1,
            perPage: HttpContext::qInt($request, 'perPage') ?? 10,
            locale: $request->query('locale') === null ? null : (string) $request->query('locale'),
            category: HttpContext::q($request, 'category'),
            year: HttpContext::qInt($request, 'year'),
            month: HttpContext::qInt($request, 'month'),
            author: HttpContext::q($request, 'author'),
        )->toPublicArray();

        return Response::json(['data' => $page['items'], 'meta' => array_diff_key($page, ['items' => 1])])
            ->withHeader('Cache-Control', self::CACHE);
    }

    /** GET /api/posts/{kind}/{slug}?locale — the post, its neighbours, related posts, translations. 301 when renamed. */
    public function show(Request $request, string $kind, string $slug): Response
    {
        $locale = $request->query('locale') === null ? null : (string) $request->query('locale');
        $detail = $this->posts->show($kind, $slug, $this->http->now(), $locale);

        if ($detail !== null) {
            return Response::json(['data' => $detail->toPublicArray()])->withHeader('Cache-Control', self::CACHE);
        }

        $moved = $this->posts->redirectFor($kind, $slug, $this->http->now(), $locale);

        return $moved === null
            ? Response::notFound('No published post lives at this address.')
            : Response::permanentRedirect('/api/posts/' . rawurlencode($moved->kind) . '/' . rawurlencode($moved->slug)
                . ($moved->locale !== '' ? '?locale=' . rawurlencode($moved->locale) : ''));
    }

    /** GET /api/posts/tags?kind&locale&limit */
    public function tags(Request $request): Response
    {
        return Response::json(['data' => $this->posts->tags(
            $this->http->now(),
            HttpContext::q($request, 'kind'),
            HttpContext::qInt($request, 'limit') ?? 50,
            $request->query('locale') === null ? null : (string) $request->query('locale'),
        )])->withHeader('Cache-Control', self::CACHE);
    }

    /** GET /api/posts/categories?kind&locale */
    public function categories(Request $request): Response
    {
        $tree = $this->categories->tree(
            $this->http->now(),
            HttpContext::q($request, 'kind'),
            $request->query('locale') === null ? null : (string) $request->query('locale'),
        );

        return Response::json(['data' => array_map(static fn(CategoryView $c): array => $c->toArray(), $tree)])
            ->withHeader('Cache-Control', self::CACHE);
    }

    /** GET /api/posts/archive?kind&locale */
    public function archive(Request $request): Response
    {
        return Response::json(['data' => $this->posts->archive(
            $this->http->now(),
            HttpContext::q($request, 'kind'),
            $request->query('locale') === null ? null : (string) $request->query('locale'),
        )])->withHeader('Cache-Control', self::CACHE);
    }

    /** GET /api/posts/popular?kind&locale&limit */
    public function popular(Request $request): Response
    {
        $posts = $this->posts->popular(
            $this->http->now(),
            HttpContext::q($request, 'kind'),
            $request->query('locale') === null ? null : (string) $request->query('locale'),
            HttpContext::qInt($request, 'limit') ?? 5,
        );

        return Response::json(['data' => array_map(static fn(PostView $v): array => $v->toPublicArray(includeBody: false), $posts)])
            ->withHeader('Cache-Control', self::CACHE);
    }

    /** POST /api/posts/views/{id} — the read beacon. Always 204: whether it counted is nobody's business. */
    public function view(Request $request, string $id): Response
    {
        $this->views->record($id, $request->ip(), $this->http->now());

        return Response::noContent()->withHeader('Cache-Control', 'no-store');
    }

    /** GET /api/posts/preview/{token} — a draft shared by link. Never cached, never indexed. */
    public function preview(Request $request, string $token): Response
    {
        $post = $this->posts->findForPreview($token, $this->http->now());

        $response = $post === null
            ? Response::notFound('This preview link is invalid or has expired.')
            : Response::json(['data' => $post->toPublicArray()]);

        return $response->withHeader('Cache-Control', 'no-store')->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
