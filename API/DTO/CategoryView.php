<?php

declare(strict_types=1);

namespace Plugins\Posts\API\DTO;

/**
 * CategoryView — one node of the category tree.
 *
 * Trees are returned FLAT, parents before children, siblings by position,
 * with `depth` for indentation — the shape a select box, a nav or a sitemap
 * renders directly. `postCount` counts published posts in this category AND
 * its descendants (a post in two of them counts once per category it is in).
 */
final readonly class CategoryView
{
    /** @param list<string> $ancestors Names from the root down to the parent — a breadcrumb. */
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public ?string $description,
        public ?string $parentId,
        public int $position,
        public int $depth,
        public array $ancestors,
        public int $postCount,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
