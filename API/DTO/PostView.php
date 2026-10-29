<?php

declare(strict_types=1);

namespace Plugins\Posts\API\DTO;

/**
 * PostView — the read model handed across the published contract.
 *
 * Primitives only: a consumer has no reason to see a Domain value object.
 * `status`, `summary`, `readingMinutes` and `path` are derived when the view
 * is built — none of them is a stored column.
 *
 *   body      the text as the author wrote it, in `bodyFormat` — for an editor.
 *   bodyHtml  the body rendered to SAFE HTML at save time — print it as-is.
 *             Null on listing views, which do not load bodies.
 *   toc       the h2–h4 headings of `bodyHtml`, each with the `id` it carries.
 *   path      the post's address on the site, from the plugin's path patterns
 *             (`/blog/{slug}` by default) — make it absolute with the host.
 */
final readonly class PostView
{
    /**
     * @param list<string>                                       $tags
     * @param list<array{id: string, slug: string, name: string}> $categories
     * @param list<array{level: int, id: string, text: string}>  $toc
     */
    public function __construct(
        public string $id,
        public string $kind,
        public string $locale,
        public string $slug,
        public string $path,
        public ?string $translationGroup,
        public string $title,
        public ?string $subtitle,
        public ?string $excerpt,
        public string $summary,
        public ?string $body,
        public string $bodyFormat,
        public ?string $bodyHtml,
        public array $toc,
        public int $readingMinutes,
        public ?string $coverImageUrl,
        public ?string $coverImageAlt,
        public ?string $authorId,
        public ?string $authorName,
        public array $tags,
        public array $categories,
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
        public ?string $publishedAt,
        public string $status,
        public int $viewCount,
        public ?string $createdBy,
        public string $createdAt,
        public string $updatedAt,
        public ?string $deletedAt,
        /** Send it back as `version` on update to be told if someone saved in between. */
        public int $version = 1,
    ) {
    }

    /**
     * The create/update input that saves this post unchanged — start from it
     * to change one field (`['published' => false] + $view->toInput()`).
     * Needs a view loaded WITH its body; a listing card would erase it.
     *
     * @return array<string, mixed>
     */
    public function toInput(): array
    {
        // A full view always has a bodyHtml STRING (empty when there is no
        // body); only a listing card carries null.
        if ($this->bodyHtml === null) {
            throw new \LogicException('This view was loaded without its body; load the post itself before saving it.');
        }

        return [
            'kind'            => $this->kind,
            'locale'          => $this->locale,
            'slug'            => $this->slug,
            'title'           => $this->title,
            'subtitle'        => $this->subtitle,
            'excerpt'         => $this->excerpt,
            'body'            => $this->body,
            'bodyFormat'      => $this->bodyFormat,
            'coverImageUrl'   => $this->coverImageUrl,
            'coverImageAlt'   => $this->coverImageAlt,
            'authorId'        => $this->authorId,
            'authorName'      => $this->authorName,
            'tags'            => $this->tags,
            'categories'      => array_column($this->categories, 'id'),
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
            'publishedAt'     => $this->publishedAt,
        ];
    }

    public function isPublic(): bool
    {
        return $this->status === 'published';
    }

    /**
     * @param bool $includeBody False for listing cards — a page of twenty
     *        posts should not ship twenty full bodies to the browser.
     * @return array<string, mixed>
     */
    public function toArray(bool $includeBody = true): array
    {
        $data = get_object_vars($this);

        if (!$includeBody) {
            unset($data['body'], $data['bodyHtml'], $data['toc']);
        }

        return $data;
    }

    /**
     * What a PUBLIC reader may see: no source text, no internal bookkeeping.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(bool $includeBody = true): array
    {
        $data = $this->toArray($includeBody);
        unset($data['body'], $data['createdBy'], $data['deletedAt'], $data['published'], $data['translationGroup'], $data['version']);

        return $data;
    }
}
