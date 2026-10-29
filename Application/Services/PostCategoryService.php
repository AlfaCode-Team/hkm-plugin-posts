<?php

declare(strict_types=1);

namespace Plugins\Posts\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use Plugins\Posts\API\Contracts\PostCategoryServiceContract;
use Plugins\Posts\API\DTO\CategoryView;
use Plugins\Posts\Application\Support\CategoryTree;
use Plugins\Posts\Domain\Entities\PostCategory;
use Plugins\Posts\Domain\Rules\PostTextRule;
use Plugins\Posts\Domain\ValueObjects\PostKind;
use Plugins\Posts\Domain\ValueObjects\PostLocale;
use Plugins\Posts\Domain\ValueObjects\PostSlug;
use Plugins\Posts\Infrastructure\Persistence\PostCategoryRepository;
use Plugins\Posts\Infrastructure\Persistence\PostRepository;

/**
 * PostCategoryService — the single implementation of
 * {@see PostCategoryServiceContract}. The tree is read whole and walked in
 * memory (see {@see CategoryTree}); writes that touch several rows run in one
 * transaction on the same connection as the repositories.
 */
final class PostCategoryService implements PostCategoryServiceContract
{
    public function __construct(
        private readonly PostCategoryRepository $categories,
        private readonly PostRepository $posts,
        private readonly PostViewFactory $views,
        private readonly TransactionManager $transaction,
        private readonly ClockPort $clock,
    ) {
    }

    public function tree(\DateTimeImmutable $now, ?string $kind = null, ?string $locale = null): array
    {
        $postKind = $kind === null || trim($kind) === '' ? null : PostKind::tryFrom(strtolower(trim($kind)));
        if ($kind !== null && trim($kind) !== '' && $postKind === null) {
            throw new ValidationException(['kind' => 'Kind must be one of: ' . implode(', ', PostKind::values()) . '.']);
        }

        try {
            $localeValue = $locale === null ? null : PostLocale::fromNullable($locale)->value();
        } catch (\DomainException $e) {
            throw new ValidationException(['locale' => $e->getMessage()]);
        }

        $tree   = $this->views->tree();
        $direct = $this->posts->categoryCounts($now, $postKind, $localeValue);

        return array_map(
            fn(array $node): CategoryView => $this->view($tree, $node['category'], $node['depth'], $direct),
            $tree->flatten(),
        );
    }

    public function find(string $id): ?CategoryView
    {
        $tree     = $this->views->tree();
        $category = $tree->find(strtoupper(trim($id)));

        return $category === null ? null : $this->view($tree, $category, count($tree->ancestors($category->id()->value())), []);
    }

    public function findBySlug(string $slug, \DateTimeImmutable $now): ?CategoryView
    {
        $tree     = $this->views->tree();
        $category = $tree->findBySlug($slug);
        if ($category === null) {
            return null;
        }

        return $this->view(
            $tree,
            $category,
            count($tree->ancestors($category->id()->value())),
            $this->posts->categoryCounts($now, null, null),
        );
    }

    public function create(array $input): CategoryView
    {
        $tree  = $this->views->tree();
        $draft = $this->validate($input, $tree, null);
        $now   = $this->clock->now();

        $category = PostCategory::create($draft['slug'], $draft['name'], $draft['description'], $draft['parentId'], $draft['position'], $now);

        $this->write('post_category.create.failed', fn() => $this->categories->insert($category));

        return $this->find($category->id()->value())
            ?? throw new ServiceException('post_category.create.failed', layer: 'service.post_categories');
    }

    public function update(string $id, array $input): ?CategoryView
    {
        $tree     = $this->views->tree();
        $category = $tree->find(strtoupper(trim($id)));
        if ($category === null) {
            return null;
        }

        $draft = $this->validate($input, $tree, $category);

        try {
            $category->change($draft['slug'], $draft['name'], $draft['description'], $draft['parentId'], $draft['position'], $this->clock->now());
        } catch (\DomainException $e) {
            throw new ValidationException(['parentId' => $e->getMessage()]);
        }

        $this->write('post_category.update.failed', fn() => $this->categories->update($category));

        return $this->find($category->id()->value());
    }

    public function delete(string $id): bool
    {
        $tree     = $this->views->tree();
        $category = $tree->find(strtoupper(trim($id)));
        if ($category === null) {
            return false;
        }

        $now      = $this->clock->now();
        $children = array_values(array_filter(array_map($tree->find(...), $tree->childrenOf($category->id()->value()))));

        $this->write('post_category.delete.failed', function () use ($category, $children, $now): void {
            foreach ($children as $child) {
                $child->reparent($category->parentId(), $now);
                $this->categories->update($child);
            }

            $this->posts->unlinkCategory($category->id()->value());
            $this->categories->delete($category->id()->value());
        });

        return true;
    }

    /**
     * @param array<string, int> $direct Public posts directly in each category.
     */
    private function view(CategoryTree $tree, PostCategory $category, int $depth, array $direct): CategoryView
    {
        $id    = $category->id()->value();
        $count = 0;
        foreach ($tree->selfAndDescendants($id) as $member) {
            $count += $direct[$member] ?? 0;
        }

        return new CategoryView(
            id: $id,
            slug: $category->slug()->value(),
            name: $category->name(),
            description: $category->description(),
            parentId: $category->parentId(),
            position: $category->position(),
            depth: $depth,
            ancestors: array_map(static fn(PostCategory $a): string => $a->name(), $tree->ancestors($id)),
            postCount: $count,
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array{slug: PostSlug, name: string, description: ?string, parentId: ?string, position: int}
     */
    private function validate(array $input, CategoryTree $tree, ?PostCategory $existing): array
    {
        $errors = [];

        $name = null;
        try {
            $raw  = $input['name'] ?? '';
            $name = PostCategory::checkName(is_scalar($raw) ? (string) $raw : '');
        } catch (\DomainException $e) {
            $errors['name'] = $e->getMessage();
        }

        $description = null;
        try {
            $description = PostTextRule::optional($input['description'] ?? null, PostCategory::DESCRIPTION_MAX, 'Description');
        } catch (\DomainException $e) {
            $errors['description'] = $e->getMessage();
        }

        $parentId = null;
        $rawParent = $input['parentId'] ?? null;
        if (is_scalar($rawParent) && trim((string) $rawParent) !== '') {
            $parentId = strtoupper(trim((string) $rawParent));
            if (!$tree->has($parentId)) {
                $errors['parentId'] = 'No category has this id.';
            } elseif ($existing !== null && $tree->wouldCycle($existing->id()->value(), $parentId)) {
                $errors['parentId'] = 'A category cannot move under itself or one of its own subcategories.';
            }
        }

        $position = 0;
        $rawPosition = $input['position'] ?? 0;
        if (!is_numeric($rawPosition) || (int) $rawPosition != $rawPosition || abs((int) $rawPosition) > 1_000_000) {
            $errors['position'] = 'Position must be a whole number.';
        } else {
            $position = (int) $rawPosition;
        }

        $slug    = null;
        $rawSlug = $input['slug'] ?? null;
        if (is_scalar($rawSlug) && trim((string) $rawSlug) !== '') {
            try {
                $slug  = PostSlug::fromString(strtolower(trim((string) $rawSlug)));
                $owner = $tree->findBySlug($slug->value());
                if ($owner !== null && ($existing === null || !$owner->id()->equals($existing->id()))) {
                    $errors['slug'] = 'Another category already uses this slug.';
                }
            } catch (\DomainException $e) {
                $errors['slug'] = $e->getMessage();
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        if ($slug === null) {
            $slug = $existing?->slug() ?? $this->freeSlug($tree, PostSlug::fromTitle((string) $name));
        }

        return [
            'slug'        => $slug,
            'name'        => (string) $name,
            'description' => $description,
            'parentId'    => $parentId,
            'position'    => $position,
        ];
    }

    private function freeSlug(CategoryTree $tree, PostSlug $base): PostSlug
    {
        $candidate = $base;
        for ($n = 2; $tree->findBySlug($candidate->value()) !== null; $n++) {
            $candidate = $base->withSuffix($n);
        }

        return $candidate;
    }

    private function write(string $code, \Closure $work): void
    {
        $this->transaction->begin();
        try {
            $work();
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            throw new ServiceException($code, layer: 'service.post_categories', previous: $e);
        } finally {
            $this->views->forgetTree();
        }
    }
}
