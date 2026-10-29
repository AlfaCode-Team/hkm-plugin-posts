<?php

declare(strict_types=1);

namespace Plugins\Posts\API\Contracts;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use Plugins\Posts\API\DTO\CategoryView;

/**
 * Published contract for the category tree of `post.publishing`.
 *
 * Like PostServiceContract it performs no authorization — the plugin's admin
 * routes check the manage permission before calling the writes.
 */
interface PostCategoryServiceContract
{
    /**
     * The whole tree, flat, parents before children. `postCount` counts
     * PUBLIC posts (as of `$now`) in each category and its descendants.
     *
     * @return list<CategoryView>
     */
    public function tree(\DateTimeImmutable $now, ?string $kind = null, ?string $locale = null): array;

    public function find(string $id): ?CategoryView;

    public function findBySlug(string $slug, \DateTimeImmutable $now): ?CategoryView;

    /**
     * @param array{name?: string, slug?: string, description?: string, parentId?: string, position?: int} $input
     * @throws ValidationException Keyed `name`, `slug`, `description`, `parentId`, `position`.
     */
    public function create(array $input): CategoryView;

    /**
     * Full replace — an omitted `slug` keeps the current one.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException Also when the new parent is the category itself or one of its descendants.
     */
    public function update(string $id, array $input): ?CategoryView;

    /**
     * Delete a category. Its posts lose it (and keep their others); its
     * children move up to its parent, so no subtree is orphaned.
     */
    public function delete(string $id): bool;
}
