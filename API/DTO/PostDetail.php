<?php

declare(strict_types=1);

namespace Plugins\Posts\API\DTO;

/**
 * PostDetail — everything a single-post page needs, in one call: the post,
 * its neighbours in publication order, related reading, and the languages it
 * is also available in. All of it public-only.
 */
final readonly class PostDetail
{
    /**
     * @param list<PostView> $related
     * @param list<PostView> $translations Other languages of this post that are published.
     */
    public function __construct(
        public PostView $post,
        public ?PostView $previous,
        public ?PostView $next,
        public array $related,
        public array $translations,
    ) {
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        $card = static fn(?PostView $v): ?array => $v?->toPublicArray(includeBody: false);

        return [
            'post'         => $this->post->toPublicArray(),
            'previous'     => $card($this->previous),
            'next'         => $card($this->next),
            'related'      => array_map($card, $this->related),
            'translations' => array_map(
                static fn(PostView $v): array => ['locale' => $v->locale, 'title' => $v->title, 'slug' => $v->slug, 'path' => $v->path],
                $this->translations,
            ),
        ];
    }
}
