<?php

declare(strict_types=1);

namespace Plugins\Posts\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Posts\API\DTO\CategoryView;
use Plugins\Posts\Application\Services\PostEditorialService;
use Plugins\Posts\Infrastructure\Http\HttpContext;

/**
 * Category management, behind `posts.editor`. Reading the tree needs any
 * editor permission (the post form shows it); changing it needs manage.
 */
final class AdminCategoryController
{
    public function __construct(
        private readonly PostEditorialService $editorial,
        private readonly HttpContext $http,
    ) {
    }

    /** GET /api/admin/post-categories */
    public function index(Request $request): Response
    {
        return Response::json(['data' => array_map(
            static fn(CategoryView $c): array => $c->toArray(),
            $this->editorial->categoryTree($this->http->now()),
        )])->withHeader('Cache-Control', 'no-store');
    }

    /** POST /api/admin/post-categories {name, slug?, description?, parentId?, position?} */
    public function store(Request $request): Response
    {
        return Response::created(['data' => $this->editorial->createCategory($request->all())->toArray()]);
    }

    /** PUT /api/admin/post-categories/{id} */
    public function update(Request $request, string $id): Response
    {
        $category = $this->editorial->updateCategory($id, $request->all());

        return $category === null ? Response::notFound('No category has this id.') : Response::json(['data' => $category->toArray()]);
    }

    /** DELETE /api/admin/post-categories/{id} — children move up, posts lose the category. */
    public function destroy(Request $request, string $id): Response
    {
        return $this->editorial->deleteCategory($id) ? Response::noContent() : Response::notFound('No category has this id.');
    }
}
