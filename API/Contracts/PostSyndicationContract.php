<?php

declare(strict_types=1);

namespace Plugins\Posts\API\Contracts;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use Plugins\Posts\API\DTO\PostView;
use Plugins\Posts\API\DTO\SiteInfo;

/**
 * Published contract for everything that carries posts OFF the site: feeds,
 * sitemaps, structured data and social meta tags. All of it public-only —
 * drafts, scheduled and trashed posts never appear — and noindex posts are
 * kept out of the sitemaps.
 *
 * Every method returns a finished document (or tag set) whose values are
 * already escaped for its format.
 */
interface PostSyndicationContract
{
    /**
     * A feed of the latest public posts.
     *
     * @param string $format 'rss' (RSS 2.0) | 'atom' (Atom 1.0) | 'json' (JSON Feed 1.1).
     * @param string $selfUrl The absolute URL this feed is served at.
     * @throws ValidationException An unknown format, kind, category or locale.
     */
    public function feed(
        string $format,
        \DateTimeImmutable $now,
        SiteInfo $site,
        string $selfUrl,
        ?string $kind = null,
        ?string $category = null,
        ?string $tag = null,
        ?string $locale = null,
        int $limit = 20,
    ): string;

    /** The media type a feed format is served with. */
    public function feedContentType(string $format): string;

    /**
     * An XML sitemap of public, indexable posts, with `lastmod`, cover images
     * and hreflang alternates between translations.
     */
    public function sitemap(\DateTimeImmutable $now, SiteInfo $site, ?string $kind = null, ?string $locale = null, int $limit = 10_000): string;

    /**
     * A Google News sitemap: public posts of the configured news kinds
     * published in the last 48 hours (Google ignores older), at most 1000.
     */
    public function newsSitemap(\DateTimeImmutable $now, SiteInfo $site): string;

    /**
     * schema.org structured data for one post — BlogPosting for a blog post,
     * Article for an article, NewsArticle for press — ready to json_encode.
     *
     * @return array<string, mixed>
     */
    public function structuredData(PostView $post, SiteInfo $site): array;

    /**
     * The `<head>` tags for a post page, escaped and ready to print: title,
     * description, canonical, robots, Open Graph, Twitter card, hreflang
     * alternates and the JSON-LD script.
     *
     * @param list<PostView> $translations Other public language versions (PostDetail::$translations).
     */
    public function headHtml(PostView $post, SiteInfo $site, array $translations = []): string;
}
