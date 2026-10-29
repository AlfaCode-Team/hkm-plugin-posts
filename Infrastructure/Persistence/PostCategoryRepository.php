<?php

declare(strict_types=1);

namespace Plugins\Posts\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Posts\Domain\Entities\PostCategory;

/**
 * PostCategoryRepository — DatabasePort ONLY. Owns `post_categories`.
 *
 * A category tree is small (tens, rarely hundreds of nodes), so `all()`
 * loads it whole and the service works on it in memory — resolving a slug,
 * walking descendants, checking a move for cycles — instead of recursive SQL,
 * which is not portable across the four drivers.
 */
final class PostCategoryRepository
{
    private const string TABLE = 'post_categories';

    private const string COLUMNS = 'id, public_id, parent_id, slug, name, description, position, created_at, updated_at';

    /** Far beyond any real taxonomy; a guard, not a page size. */
    private const int MAX_ROWS = 5000;

    public function __construct(
        private readonly DatabasePort $db,
    ) {
    }

    /** @return list<PostCategory> Ordered by position, then name. */
    public function all(): array
    {
        try {
            $rows = $this->db->query(
                'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . ' ORDER BY position ASC, name ASC, id ASC LIMIT ' . self::MAX_ROWS,
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to load categories.', layer: 'repository.post_categories', previous: $e);
        }

        return array_map(static fn(array $row): PostCategory => PostCategory::reconstitute($row), array_values($rows));
    }

    public function insert(PostCategory $category): void
    {
        $this->write(
            'INSERT INTO ' . self::TABLE . ' (public_id, parent_id, slug, name, description, position, created_at, updated_at)
             VALUES (:public_id, :parent_id, :slug, :name, :description, :position, :created_at, :updated_at)',
            $this->toRow($category),
            'Failed to insert category.',
        );
    }

    public function update(PostCategory $category): bool
    {
        $row = $this->toRow($category);
        unset($row['created_at']);

        return $this->write(
            'UPDATE ' . self::TABLE . ' SET parent_id = :parent_id, slug = :slug, name = :name,
                description = :description, position = :position, updated_at = :updated_at
             WHERE public_id = :public_id',
            $row,
            'Failed to update category.',
        ) > 0;
    }

    public function delete(string $publicId): bool
    {
        return $this->write(
            'DELETE FROM ' . self::TABLE . ' WHERE public_id = :public_id',
            ['public_id' => $publicId],
            'Failed to delete category.',
        ) > 0;
    }

    /** @return array<string, mixed> */
    private function toRow(PostCategory $category): array
    {
        return [
            'public_id'   => $category->id()->value(),
            'parent_id'   => $category->parentId(),
            'slug'        => $category->slug()->value(),
            'name'        => $category->name(),
            'description' => $category->description(),
            'position'    => $category->position(),
            'created_at'  => PostRepository::formatInstant($category->createdAt()),
            'updated_at'  => PostRepository::formatInstant($category->updatedAt()),
        ];
    }

    /** @param array<string, mixed> $params */
    private function write(string $sql, array $params, string $message): int
    {
        try {
            return $this->db->execute($sql, $params);
        } catch (\Throwable $e) {
            throw new RepositoryException(
                $message,
                layer: 'repository.post_categories',
                context: ['categoryId' => $params['public_id'] ?? null],
                previous: $e,
            );
        }
    }
}
