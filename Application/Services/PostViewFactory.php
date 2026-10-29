<?php

declare(strict_types=1);

namespace Plugins\Posts\Application\Services;

use Plugins\Posts\API\DTO\PostView;
use Plugins\Posts\Application\Support\CategoryTree;
use Plugins\Posts\Application\Support\PostPaths;
use Plugins\Posts\Domain\Entities\Post;
use Plugins\Posts\Infrastructure\Persistence\PostCategoryRepository;

/**
 * PostViewFactory — the one place a {@see Post} becomes a {@see PostView}.
 *
 * Holds the category tree for the life of the request (loaded on first use),
 * so a page of twenty posts names its categories with one query, not twenty.
 * Request-scoped: built per container, never static.
 */
final class PostViewFactory
{
    private ?CategoryTree $tree = null;

    public function __construct(
        private readonly PostPaths $paths,
        private readonly PostCategoryRepository $categories,
    ) {
    }

    public function view(Post $post, \DateTimeImmutable $now, bool $withBody = true): PostView
    {
        $c    = $post->content();
        $tree = $this->tree();
        $utc  = new \DateTimeZone('UTC');

        $categories = [];
        foreach ($c->categoryIds as $id) {
            $category = $tree->find($id);
            if ($category !== null) {
                $categories[] = ['id' => $id, 'slug' => $category->slug()->value(), 'name' => $category->name()];
            }
        }

        return new PostView(
            id: $post->id()->value(),
            kind: $c->kind->value,
            locale: $c->locale->value(),
            slug: $c->slug->value(),
            path: $this->paths->pathFor($c->kind->value, $c->slug->value(), $c->locale->value()),
            translationGroup: $post->translationGroup(),
            title: $c->title->value(),
            subtitle: $c->subtitle,
            excerpt: $c->excerpt,
            summary: $post->summary(),
            body: $withBody && !$post->isPartial() ? $c->body?->value() : null,
            bodyFormat: $c->bodyFormat->value,
            bodyHtml: $withBody && !$post->isPartial() ? $post->rendered()->html : null,
            toc: $withBody && !$post->isPartial() ? $post->rendered()->toc : [],
            readingMinutes: $post->readingMinutes(),
            coverImageUrl: $c->coverImageUrl,
            coverImageAlt: $c->coverImageAlt,
            authorId: $c->authorId,
            authorName: $c->authorName,
            tags: $c->tagValues(),
            categories: $categories,
            dateline: $c->dateline,
            contact: $c->contact,
            sourceName: $c->sourceName,
            sourceUrl: $c->sourceUrl,
            metaTitle: $c->metaTitle,
            metaDescription: $c->metaDescription,
            canonicalUrl: $c->canonicalUrl,
            noindex: $c->noindex,
            featured: $c->featured,
            commentsEnabled: $c->commentsEnabled,
            published: $c->published,
            publishedAt: $c->publishedAt?->setTimezone($utc)->format(\DateTimeInterface::ATOM),
            status: $post->statusAt($now)->value,
            viewCount: $post->viewCount(),
            createdBy: $post->createdBy(),
            createdAt: $post->createdAt()->setTimezone($utc)->format(\DateTimeInterface::ATOM),
            updatedAt: $post->updatedAt()->setTimezone($utc)->format(\DateTimeInterface::ATOM),
            deletedAt: $post->deletedAt()?->setTimezone($utc)->format(\DateTimeInterface::ATOM),
            version: $post->version(),
        );
    }

    public function path(Post $post): string
    {
        $c = $post->content();

        return $this->paths->pathFor($c->kind->value, $c->slug->value(), $c->locale->value());
    }

    /** The public path from stored columns, for listings that skip the entity. */
    public function pathOf(string $kind, string $slug, string $locale): string
    {
        return $this->paths->pathFor($kind, $slug, $locale);
    }

    public function tree(): CategoryTree
    {
        return $this->tree ??= new CategoryTree($this->categories->all());
    }

    /** Drop the cached tree after a category write in the same request. */
    public function forgetTree(): void
    {
        $this->tree = null;
    }
}
