<?php

declare(strict_types=1);

namespace Plugins\Posts\Domain\Entities;

use Plugins\Posts\Domain\ValueObjects\CategoryId;
use Plugins\Posts\Domain\ValueObjects\PostSlug;

/**
 * PostCategory — one node of the category tree (`post_categories`).
 *
 * Categories are shared by every kind: "Elections" can hold a blog post, an
 * article and a press release. A post may sit in several categories, and a
 * listing by category includes its descendants, so filing a post under
 * "Elections › 2026" also shows it under "Elections".
 *
 * The tree's shape (no cycles, a parent that exists) is checked by the
 * service, which can see every node; this class guards only its own fields.
 */
final class PostCategory
{
    public const int NAME_MAX        = 80;
    public const int DESCRIPTION_MAX = 500;

    private function __construct(
        private readonly CategoryId $id,
        private PostSlug $slug,
        private string $name,
        private ?string $description,
        private ?string $parentId,
        private int $position,
        private readonly \DateTimeImmutable $createdAt,
        private \DateTimeImmutable $updatedAt,
    ) {
    }

    public static function create(
        PostSlug $slug,
        string $name,
        ?string $description,
        ?string $parentId,
        int $position,
        \DateTimeImmutable $now,
    ): self {
        return new self(CategoryId::generate(), $slug, self::checkName($name), $description, $parentId, $position, $now, $now);
    }

    /** @param array<string, mixed> $row */
    public static function reconstitute(array $row): self
    {
        $utc = new \DateTimeZone('UTC');

        return new self(
            id: CategoryId::fromString((string) $row['public_id']),
            slug: PostSlug::fromString((string) $row['slug']),
            name: (string) $row['name'],
            description: ($row['description'] ?? '') === '' ? null : (string) $row['description'],
            parentId: ($row['parent_id'] ?? '') === '' ? null : (string) $row['parent_id'],
            position: (int) ($row['position'] ?? 0),
            createdAt: new \DateTimeImmutable((string) ($row['created_at'] ?? 'now'), $utc),
            updatedAt: new \DateTimeImmutable((string) ($row['updated_at'] ?? 'now'), $utc),
        );
    }

    public function change(PostSlug $slug, string $name, ?string $description, ?string $parentId, int $position, \DateTimeImmutable $now): void
    {
        if ($parentId !== null && $parentId === $this->id->value()) {
            throw new \DomainException('A category cannot be its own parent.');
        }

        $this->slug        = $slug;
        $this->name        = self::checkName($name);
        $this->description = $description;
        $this->parentId    = $parentId;
        $this->position    = $position;
        $this->updatedAt   = $now;
    }

    /** Re-attach to a new parent — used when this node's parent is deleted. */
    public function reparent(?string $parentId, \DateTimeImmutable $now): void
    {
        $this->parentId  = $parentId;
        $this->updatedAt = $now;
    }

    public static function checkName(string $name): string
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            throw new \DomainException('Category name is required.');
        }

        if (mb_strlen($trimmed) > self::NAME_MAX) {
            throw new \DomainException('Category name must not exceed ' . self::NAME_MAX . ' characters.');
        }

        return $trimmed;
    }

    public function id(): CategoryId
    {
        return $this->id;
    }

    public function slug(): PostSlug
    {
        return $this->slug;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function parentId(): ?string
    {
        return $this->parentId;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
