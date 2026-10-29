<?php

declare(strict_types=1);

namespace Plugins\Posts\API\DTO;

/**
 * PostPage — one page of a listing, with what a pager needs to draw itself.
 *
 * Offset pagination on purpose: a blog archive shows numbered pages
 * (`/blog?page=3`), which keyset pagination cannot address. `page` is the
 * page actually served, after clamping to at least 1. Listing views carry no
 * body — load the post itself for that.
 */
final readonly class PostPage
{
    /**
     * @param list<PostView> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {
    }

    /** 1 for an empty listing, so "page 1 of 1" never reads "page 1 of 0". */
    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }

    public function hasMore(): bool
    {
        return $this->page < $this->lastPage();
    }

    /** @return array{total: int, page: int, perPage: int, lastPage: int, hasMore: bool} */
    public function meta(): array
    {
        return [
            'total'    => $this->total,
            'page'     => $this->page,
            'perPage'  => $this->perPage,
            'lastPage' => $this->lastPage(),
            'hasMore'  => $this->hasMore(),
        ];
    }

    /** @return array<string, mixed> Admin shape — every field but the body. */
    public function toArray(): array
    {
        return [
            'items' => array_map(static fn(PostView $v): array => $v->toArray(includeBody: false), $this->items),
            ...$this->meta(),
        ];
    }

    /** @return array<string, mixed> Public shape — see {@see PostView::toPublicArray()}. */
    public function toPublicArray(): array
    {
        return [
            'items' => array_map(static fn(PostView $v): array => $v->toPublicArray(includeBody: false), $this->items),
            ...$this->meta(),
        ];
    }
}
