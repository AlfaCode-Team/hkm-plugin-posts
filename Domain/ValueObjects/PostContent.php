<?php

declare(strict_types=1);

namespace Plugins\Posts\Domain\ValueObjects;

/**
 * PostContent — every EDITABLE field of a post, already validated.
 *
 * Bundled so that `Post::create()` and `Post::replaceContent()` take one
 * argument instead of twenty-five, and so the two can never disagree about
 * which fields an editor controls. Identity (`id`), translation membership and
 * bookkeeping (`createdBy`, `deletedAt`, …) are deliberately NOT here — no
 * edit changes them.
 *
 * {@see self::toInput()} is the inverse of the service's validation: it
 * produces the input array that would recreate this content. A revision is
 * stored in that shape, so restoring one is just an update with it.
 */
final readonly class PostContent
{
    /**
     * @param list<PostTag> $tags        De-duplicated, in the order the editor gave them.
     * @param list<string>  $categoryIds Category public ids, de-duplicated, existence checked by the service.
     */
    public function __construct(
        public PostKind $kind,
        public PostLocale $locale,
        public PostSlug $slug,
        public PostTitle $title,
        public ?string $subtitle,
        public ?string $excerpt,
        public ?PostBody $body,
        public BodyFormat $bodyFormat,
        public ?string $coverImageUrl,
        public ?string $coverImageAlt,
        public ?string $authorId,
        public ?string $authorName,
        public array $tags,
        public array $categoryIds,
        public ?string $dateline,
        public ?string $contact,
        public ?string $sourceName,
        public ?string $sourceUrl,
        public ?string $metaTitle,
        public ?string $metaDescription,
        public ?string $canonicalUrl,
        public bool $noindex,
        public bool $featured,
        public bool $commentsEnabled,
        public bool $published,
        public ?\DateTimeImmutable $publishedAt,
    ) {
    }

    /** A copy with the named fields replaced: `$content->with(slug: $slug)`. */
    public function with(mixed ...$changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    public function withPublishedAt(?\DateTimeImmutable $publishedAt): self
    {
        return $this->with(publishedAt: $publishedAt);
    }

    /** @return list<string> */
    public function tagValues(): array
    {
        return array_map(static fn(PostTag $tag): string => $tag->value(), $this->tags);
    }

    /**
     * The create/update input that reproduces this content — the shape a
     * revision is stored in.
     *
     * @return array<string, mixed>
     */
    public function toInput(): array
    {
        return [
            'kind'            => $this->kind->value,
            'locale'          => $this->locale->value(),
            'slug'            => $this->slug->value(),
            'title'           => $this->title->value(),
            'subtitle'        => $this->subtitle,
            'excerpt'         => $this->excerpt,
            'body'            => $this->body?->value(),
            'bodyFormat'      => $this->bodyFormat->value,
            'coverImageUrl'   => $this->coverImageUrl,
            'coverImageAlt'   => $this->coverImageAlt,
            'authorId'        => $this->authorId,
            'authorName'      => $this->authorName,
            'tags'            => $this->tagValues(),
            'categories'      => $this->categoryIds,
            'dateline'        => $this->dateline,
            'contact'         => $this->contact,
            'sourceName'      => $this->sourceName,
            'sourceUrl'       => $this->sourceUrl,
            'metaTitle'       => $this->metaTitle,
            'metaDescription' => $this->metaDescription,
            'canonicalUrl'    => $this->canonicalUrl,
            'noindex'         => $this->noindex,
            'featured'        => $this->featured,
            'commentsEnabled' => $this->commentsEnabled,
            'published'       => $this->published,
            'publishedAt'     => $this->publishedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
