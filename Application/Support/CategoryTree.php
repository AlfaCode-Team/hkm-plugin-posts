<?php

declare(strict_types=1);

namespace Plugins\Posts\Application\Support;

use Plugins\Posts\Domain\Entities\PostCategory;

/**
 * CategoryTree — the whole category tree in memory, with the walks the
 * services need: slug lookup, descendants, ancestors, a depth-first flat
 * order, and cycle detection for a move.
 *
 * Built from one `SELECT` per request; a taxonomy is small, and walking it in
 * PHP keeps the SQL portable (no recursive CTEs). A node whose parent is
 * missing — a row edited by hand — is treated as top level rather than lost.
 */
final class CategoryTree
{
    /** @var array<string, PostCategory> */
    private array $byId = [];

    /** @var array<string, string> slug => id */
    private array $bySlug = [];

    /** @var array<string, list<string>> parent id ('' = root) => child ids, in order */
    private array $children = [];

    /** @param list<PostCategory> $categories Already ordered by position, then name. */
    public function __construct(array $categories)
    {
        foreach ($categories as $category) {
            $id                                     = $category->id()->value();
            $this->byId[$id]                        = $category;
            $this->bySlug[$category->slug()->value()] = $id;
        }

        foreach ($categories as $category) {
            $parent = $category->parentId();
            $key    = $parent !== null && isset($this->byId[$parent]) ? $parent : '';
            $this->children[$key][] = $category->id()->value();
        }
    }

    public function find(string $id): ?PostCategory
    {
        return $this->byId[$id] ?? null;
    }

    public function findBySlug(string $slug): ?PostCategory
    {
        $id = $this->bySlug[strtolower(trim($slug))] ?? null;

        return $id === null ? null : $this->byId[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->byId[$id]);
    }

    /** @return list<string> The node and every node below it. */
    public function selfAndDescendants(string $id): array
    {
        $out   = [];
        $stack = [$id];
        while ($stack !== []) {
            $current = array_pop($stack);
            if (isset($out[$current])) {
                continue; // a hand-made cycle must not loop forever
            }
            $out[$current] = true;
            foreach ($this->children[$current] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return array_keys($out);
    }

    /** @return list<PostCategory> Root first, the node's parent last. */
    public function ancestors(string $id): array
    {
        $chain = [];
        $seen  = [$id => true];
        $node  = $this->byId[$id] ?? null;

        while ($node !== null && ($parent = $node->parentId()) !== null && !isset($seen[$parent]) && isset($this->byId[$parent])) {
            $seen[$parent] = true;
            $node          = $this->byId[$parent];
            array_unshift($chain, $node);
        }

        return $chain;
    }

    /** Would making `$parentId` the parent of `$id` create a loop? */
    public function wouldCycle(string $id, ?string $parentId): bool
    {
        return $parentId !== null && in_array($parentId, $this->selfAndDescendants($id), true);
    }

    /** @return list<string> Direct children of `$id` ('' for the roots). */
    public function childrenOf(string $id): array
    {
        return $this->children[$id] ?? [];
    }

    /**
     * Every node, parents before children, siblings in their stored order.
     *
     * @return list<array{category: PostCategory, depth: int}>
     */
    public function flatten(): array
    {
        $out  = [];
        $seen = [];
        $walk = function (string $parent, int $depth) use (&$walk, &$out, &$seen): void {
            foreach ($this->children[$parent] ?? [] as $id) {
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $out[]     = ['category' => $this->byId[$id], 'depth' => $depth];
                $walk($id, $depth + 1);
            }
        };
        $walk('', 0);

        return $out;
    }
}
