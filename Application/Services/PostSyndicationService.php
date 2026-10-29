<?php

declare(strict_types=1);

namespace Plugins\Posts\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use Plugins\Posts\API\Contracts\PostSyndicationContract;
use Plugins\Posts\API\DTO\PostView;
use Plugins\Posts\API\DTO\SiteInfo;
use Plugins\Posts\Domain\Entities\Post;
use Plugins\Posts\Domain\ValueObjects\PostKind;
use Plugins\Posts\Domain\ValueObjects\PostLocale;
use Plugins\Posts\Domain\ValueObjects\PostStatus;
use Plugins\Posts\Domain\ValueObjects\PostTag;
use Plugins\Posts\Infrastructure\Persistence\PostCriteria;
use Plugins\Posts\Infrastructure\Persistence\PostRepository;

/**
 * PostSyndicationService — feeds, sitemaps, structured data and head tags.
 *
 * Every XML document is written with XMLWriter, which escapes every value it
 * is given, so no title or URL can break out of its element. The JSON-LD in
 * {@see self::headHtml()} is encoded with JSON_HEX_TAG, so a title containing
 * `</script>` cannot close the script tag it sits in.
 */
final class PostSyndicationService implements PostSyndicationContract
{
    private const array FORMATS = [
        'rss'  => 'application/rss+xml; charset=utf-8',
        'atom' => 'application/atom+xml; charset=utf-8',
        'json' => 'application/feed+json; charset=utf-8',
    ];

    /** Google News only reads articles from the last two days, and at most this many. */
    private const int NEWS_WINDOW_HOURS = 48;
    private const int NEWS_MAX          = 1000;

    /** Sitemaps cap at 50,000 URLs; the default stays well under it. */
    private const int SITEMAP_MAX = 50_000;

    /**
     * @param list<string> $newsKinds Kinds that belong in the news sitemap.
     */
    public function __construct(
        private readonly PostRepository $posts,
        private readonly PostViewFactory $views,
        private readonly array $newsKinds = ['press', 'article'],
        private readonly bool $fullContentFeeds = true,
    ) {
    }

    public function feedContentType(string $format): string
    {
        return self::FORMATS[$format] ?? throw new ValidationException(['format' => 'Feed format must be rss, atom or json.']);
    }

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
    ): string {
        $this->feedContentType($format);

        $criteria = $this->criteria($now, $kind, $category, $tag, $locale);
        $posts    = $criteria === null ? [] : $this->posts->search($criteria, max(1, min($limit, 100)), 0, withBody: $this->fullContentFeeds);
        $views    = array_map(fn(Post $p): PostView => $this->views->view($p, $now, withBody: $this->fullContentFeeds), $posts);

        $title = $site->name . ($kind !== null && trim($kind) !== '' ? ' — ' . ucfirst(strtolower(trim($kind))) : '');

        return match ($format) {
            'rss'  => $this->rss($views, $site, $selfUrl, $title, $now),
            'atom' => $this->atom($views, $site, $selfUrl, $title, $now),
            'json' => $this->jsonFeed($views, $site, $selfUrl, $title),
        };
    }

    public function sitemap(\DateTimeImmutable $now, SiteInfo $site, ?string $kind = null, ?string $locale = null, int $limit = 10_000): string
    {
        $criteria = $this->criteria($now, $kind, null, null, $locale);
        $limit    = max(1, min($limit, self::SITEMAP_MAX));

        // One lean statement: just the columns a <url> needs, noindex
        // filtered in SQL. Rows stay plain arrays; building a PostView per post
        // cost more than the XML itself.
        $utc     = new \DateTimeZone('UTC');
        $entries = [];
        foreach ($criteria === null ? [] : $this->posts->sitemapRows($criteria, $limit) as $row) {
            $locale    = (string) $row['locale'];
            $entries[] = [
                'path'    => $this->views->pathOf((string) $row['kind'], (string) $row['slug'], $locale),
                'locale'  => $locale,
                'group'   => $row['translation_group'] !== null && $row['translation_group'] !== '' ? (string) $row['translation_group'] : null,
                'cover'   => $row['cover_image_url'] !== null && $row['cover_image_url'] !== '' ? (string) $row['cover_image_url'] : null,
                'lastmod' => (new \DateTimeImmutable((string) ($row['updated_at'] ?? 'now'), $utc))->format(\DateTimeInterface::ATOM),
            ];
        }

        // hreflang alternates come from the set itself: a translation that is
        // not public (or not in this sitemap) must not be advertised.
        $groups = [];
        foreach ($entries as $entry) {
            if ($entry['group'] !== null) {
                $groups[$entry['group']][] = $entry;
            }
        }
        // Alternates in a stable order (by locale), so the document does not
        // change just because publication dates did.
        foreach ($groups as &$members) {
            usort($members, static fn(array $a, array $b): int => $a['locale'] <=> $b['locale']);
        }
        unset($members);

        $x = self::xml();
        $x->startElement('urlset');
        $x->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $x->writeAttribute('xmlns:xhtml', 'http://www.w3.org/1999/xhtml');
        $x->writeAttribute('xmlns:image', 'http://www.google.com/schemas/sitemap-image/1.1');

        foreach ($entries as $entry) {
            $x->startElement('url');
            $x->writeElement('loc', $site->absolute($entry['path']));
            $x->writeElement('lastmod', $entry['lastmod']);

            $siblings = $entry['group'] === null ? [] : $groups[$entry['group']];
            if (count($siblings) > 1) {
                foreach ($siblings as $sibling) {
                    $x->startElement('xhtml:link');
                    $x->writeAttribute('rel', 'alternate');
                    $x->writeAttribute('hreflang', $site->languageOf($sibling['locale']));
                    $x->writeAttribute('href', $site->absolute($sibling['path']));
                    $x->endElement();
                }
            }

            if ($entry['cover'] !== null) {
                $x->startElement('image:image');
                $x->writeElement('image:loc', $site->absolute($entry['cover']));
                $x->endElement();
            }

            $x->endElement();
        }

        $x->endElement();

        return $x->outputMemory();
    }

    public function newsSitemap(\DateTimeImmutable $now, SiteInfo $site): string
    {
        $views = [];
        foreach ($this->newsKinds as $kind) {
            $postKind = PostKind::tryFrom($kind);
            if ($postKind === null) {
                continue;
            }

            $criteria = new PostCriteria(
                now: $now,
                kind: $postKind,
                status: PostStatus::Published,
                publishedFrom: $now->modify('-' . self::NEWS_WINDOW_HOURS . ' hours'),
                order: PostCriteria::ORDER_PUBLISHED,
            );

            for ($offset = 0; count($views) < self::NEWS_MAX; $offset += PostRepository::MAX_PER_PAGE) {
                $batch = $this->posts->search($criteria, PostRepository::MAX_PER_PAGE, $offset);
                foreach ($batch as $post) {
                    if (!$post->content()->noindex && count($views) < self::NEWS_MAX) {
                        $views[] = $this->views->view($post, $now, withBody: false);
                    }
                }
                if (count($batch) < PostRepository::MAX_PER_PAGE) {
                    break;
                }
            }
        }

        $x = self::xml();
        $x->startElement('urlset');
        $x->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $x->writeAttribute('xmlns:news', 'http://www.google.com/schemas/sitemap-news/0.9');

        foreach ($views as $view) {
            $x->startElement('url');
            $x->writeElement('loc', $site->absolute($view->path));
            $x->startElement('news:news');
            $x->startElement('news:publication');
            $x->writeElement('news:name', $site->name);
            $x->writeElement('news:language', strtolower(explode('-', $site->languageOf($view->locale))[0]));
            $x->endElement();
            $x->writeElement('news:publication_date', (string) $view->publishedAt);
            $x->writeElement('news:title', $view->title);
            $x->endElement();
            $x->endElement();
        }

        $x->endElement();

        return $x->outputMemory();
    }

    public function structuredData(PostView $post, SiteInfo $site): array
    {
        $url = $post->canonicalUrl ?? $site->absolute($post->path);

        $data = [
            '@context'         => 'https://schema.org',
            '@type'            => match ($post->kind) {
                'press'   => 'NewsArticle',
                'article' => 'Article',
                default   => 'BlogPosting',
            },
            'headline'         => mb_substr($post->title, 0, 110),
            'description'      => $post->metaDescription ?? $post->summary,
            'url'              => $site->absolute($post->path),
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
            'datePublished'    => $post->publishedAt,
            'dateModified'     => $post->updatedAt,
            'inLanguage'       => $site->languageOf($post->locale),
            'publisher'        => array_filter([
                '@type' => 'Organization',
                'name'  => $site->name,
                'logo'  => $site->logoUrl === null ? null : ['@type' => 'ImageObject', 'url' => $site->absolute($site->logoUrl)],
            ]),
        ];

        if ($post->coverImageUrl !== null) {
            $data['image'] = [$site->absolute($post->coverImageUrl)];
        }
        if ($post->authorName !== null) {
            $data['author'] = [['@type' => 'Person', 'name' => $post->authorName]];
        }
        if ($post->tags !== []) {
            $data['keywords'] = implode(', ', $post->tags);
        }
        if ($post->categories !== []) {
            $data['articleSection'] = $post->categories[0]['name'];
        }
        if ($post->readingMinutes > 0) {
            $data['timeRequired'] = 'PT' . $post->readingMinutes . 'M';
        }
        if ($post->kind === 'press' && $post->dateline !== null) {
            $data['dateline'] = $post->dateline;
        }

        return $data;
    }

    public function headHtml(PostView $post, SiteInfo $site, array $translations = []): string
    {
        $e     = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
        $title = $post->metaTitle ?? $post->title;
        $desc  = $post->metaDescription ?? $post->summary;
        $url   = $site->absolute($post->path);
        $lines = [];

        $meta = static function (string $attr, string $key, ?string $content) use (&$lines, $e): void {
            if ($content !== null && $content !== '') {
                $lines[] = '<meta ' . $attr . '="' . $e($key) . '" content="' . $e($content) . '">';
            }
        };

        $lines[] = '<title>' . $e($title) . ' | ' . $e($site->name) . '</title>';
        $meta('name', 'description', $desc);
        $lines[] = '<link rel="canonical" href="' . $e($post->canonicalUrl ?? $url) . '">';
        if ($post->noindex) {
            $meta('name', 'robots', 'noindex, follow');
        }

        $meta('property', 'og:type', 'article');
        $meta('property', 'og:title', $title);
        $meta('property', 'og:description', $desc);
        $meta('property', 'og:url', $url);
        $meta('property', 'og:site_name', $site->name);
        $meta('property', 'og:locale', str_replace('-', '_', $site->languageOf($post->locale)));
        if ($post->coverImageUrl !== null) {
            $meta('property', 'og:image', $site->absolute($post->coverImageUrl));
            $meta('property', 'og:image:alt', $post->coverImageAlt);
        }
        $meta('property', 'article:published_time', $post->publishedAt);
        $meta('property', 'article:modified_time', $post->updatedAt);
        foreach ($post->tags as $tag) {
            $meta('property', 'article:tag', $tag);
        }

        $meta('name', 'twitter:card', $post->coverImageUrl !== null ? 'summary_large_image' : 'summary');
        $meta('name', 'twitter:title', $title);
        $meta('name', 'twitter:description', $desc);
        if ($post->coverImageUrl !== null) {
            $meta('name', 'twitter:image', $site->absolute($post->coverImageUrl));
        }
        if ($site->twitterHandle !== null) {
            $meta('name', 'twitter:site', '@' . ltrim($site->twitterHandle, '@'));
        }

        if ($translations !== []) {
            foreach ([$post, ...$translations] as $version) {
                $lines[] = '<link rel="alternate" hreflang="' . $e($site->languageOf($version->locale)) . '" href="' . $e($site->absolute($version->path)) . '">';
            }
        }

        $json = json_encode(
            $this->structuredData($post, $site),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
        );
        $lines[] = '<script type="application/ld+json">' . $json . '</script>';

        return implode("\n", $lines);
    }

    // ── formats ──────────────────────────────────────────────────────────

    /** @param list<PostView> $views */
    private function rss(array $views, SiteInfo $site, string $selfUrl, string $title, \DateTimeImmutable $now): string
    {
        $x = self::xml();
        $x->startElement('rss');
        $x->writeAttribute('version', '2.0');
        $x->writeAttribute('xmlns:atom', 'http://www.w3.org/2005/Atom');
        $x->writeAttribute('xmlns:content', 'http://purl.org/rss/1.0/modules/content/');
        $x->writeAttribute('xmlns:dc', 'http://purl.org/dc/elements/1.1/');

        $x->startElement('channel');
        $x->writeElement('title', $title);
        $x->writeElement('link', $site->baseUrl . '/');
        $x->writeElement('description', $site->description !== '' ? $site->description : $site->name);
        $x->writeElement('language', $site->defaultLocale);
        $x->writeElement('lastBuildDate', ($views[0] ?? null) !== null ? self::rfc822($views[0]->publishedAt) : $now->format(DATE_RSS));
        $x->startElement('atom:link');
        $x->writeAttribute('href', $selfUrl);
        $x->writeAttribute('rel', 'self');
        $x->writeAttribute('type', 'application/rss+xml');
        $x->endElement();

        foreach ($views as $view) {
            $url = $site->absolute($view->path);
            $x->startElement('item');
            $x->writeElement('title', $view->title);
            $x->writeElement('link', $url);
            $x->startElement('guid');
            $x->writeAttribute('isPermaLink', 'false');
            $x->text($view->id);
            $x->endElement();
            $x->writeElement('pubDate', self::rfc822($view->publishedAt));
            if ($view->authorName !== null) {
                $x->writeElement('dc:creator', $view->authorName);
            }
            foreach ($view->categories as $category) {
                $x->writeElement('category', $category['name']);
            }
            foreach ($view->tags as $tag) {
                $x->writeElement('category', $tag);
            }
            $x->writeElement('description', $view->summary);
            if ($view->bodyHtml !== null && $view->bodyHtml !== '') {
                $x->startElement('content:encoded');
                $x->writeCdata(str_replace(']]>', ']]]]><![CDATA[>', $view->bodyHtml));
                $x->endElement();
            }
            if ($view->coverImageUrl !== null) {
                $x->startElement('enclosure');
                $x->writeAttribute('url', $site->absolute($view->coverImageUrl));
                $x->writeAttribute('type', self::imageType($view->coverImageUrl));
                $x->writeAttribute('length', '0');
                $x->endElement();
            }
            $x->endElement();
        }

        $x->endElement();
        $x->endElement();

        return $x->outputMemory();
    }

    /** @param list<PostView> $views */
    private function atom(array $views, SiteInfo $site, string $selfUrl, string $title, \DateTimeImmutable $now): string
    {
        $x = self::xml();
        $x->startElement('feed');
        $x->writeAttribute('xmlns', 'http://www.w3.org/2005/Atom');
        $x->writeAttribute('xml:lang', $site->defaultLocale);

        $x->writeElement('id', $selfUrl);
        $x->writeElement('title', $title);
        $x->writeElement('updated', $views !== [] ? (string) max(array_map(static fn(PostView $v): string => $v->updatedAt, $views)) : $now->format(DATE_ATOM));
        self::atomLink($x, $selfUrl, 'self', 'application/atom+xml');
        self::atomLink($x, $site->baseUrl . '/', 'alternate', 'text/html');
        $x->startElement('author');
        $x->writeElement('name', $site->name);
        $x->endElement();

        foreach ($views as $view) {
            $url = $site->absolute($view->path);
            $x->startElement('entry');
            $x->writeElement('id', 'urn:post:' . $view->id);
            $x->writeElement('title', $view->title);
            self::atomLink($x, $url, 'alternate', 'text/html');
            $x->writeElement('published', (string) $view->publishedAt);
            $x->writeElement('updated', $view->updatedAt);
            if ($view->authorName !== null) {
                $x->startElement('author');
                $x->writeElement('name', $view->authorName);
                $x->endElement();
            }
            foreach ([...array_column($view->categories, 'name'), ...$view->tags] as $term) {
                $x->startElement('category');
                $x->writeAttribute('term', $term);
                $x->endElement();
            }
            $x->startElement('summary');
            $x->writeAttribute('type', 'text');
            $x->text($view->summary);
            $x->endElement();
            if ($view->bodyHtml !== null && $view->bodyHtml !== '') {
                $x->startElement('content');
                $x->writeAttribute('type', 'html');
                $x->text($view->bodyHtml);
                $x->endElement();
            }
            $x->endElement();
        }

        $x->endElement();

        return $x->outputMemory();
    }

    /** @param list<PostView> $views */
    private function jsonFeed(array $views, SiteInfo $site, string $selfUrl, string $title): string
    {
        $feed = [
            'version'       => 'https://jsonfeed.org/version/1.1',
            'title'         => $title,
            'home_page_url' => $site->baseUrl . '/',
            'feed_url'      => $selfUrl,
            'language'      => $site->defaultLocale,
            'items'         => array_map(static fn(PostView $v): array => array_filter([
                'id'             => $v->id,
                'url'            => $site->absolute($v->path),
                'title'          => $v->title,
                'summary'        => $v->summary,
                'content_html'   => $v->bodyHtml !== null && $v->bodyHtml !== '' ? $v->bodyHtml : null,
                'content_text'   => $v->bodyHtml === null || $v->bodyHtml === '' ? $v->summary : null,
                'image'          => $v->coverImageUrl === null ? null : $site->absolute($v->coverImageUrl),
                'date_published' => $v->publishedAt,
                'date_modified'  => $v->updatedAt,
                'authors'        => $v->authorName === null ? null : [['name' => $v->authorName]],
                'tags'           => [...array_column($v->categories, 'name'), ...$v->tags] ?: null,
                'language'       => $site->languageOf($v->locale),
            ], static fn(mixed $value): bool => $value !== null), $views),
        ];

        if ($site->description !== '') {
            $feed['description'] = $site->description;
        }

        return json_encode($feed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** The public listing criteria for a feed, or null when a filter can match nothing. */
    private function criteria(\DateTimeImmutable $now, ?string $kind, ?string $category, ?string $tag, ?string $locale): ?PostCriteria
    {
        $errors   = [];
        $postKind = null;
        if ($kind !== null && trim($kind) !== '') {
            $postKind = PostKind::tryFrom(strtolower(trim($kind)));
            if ($postKind === null) {
                $errors['kind'] = 'Kind must be one of: ' . implode(', ', PostKind::values()) . '.';
            }
        }

        $categoryIds = null;
        if ($category !== null && trim($category) !== '') {
            $node = $this->views->tree()->findBySlug($category);
            if ($node === null) {
                $errors['category'] = 'No category has this slug.';
            } else {
                $categoryIds = $this->views->tree()->selfAndDescendants($node->id()->value());
            }
        }

        $localeValue = null;
        if ($locale !== null) {
            try {
                $localeValue = PostLocale::fromNullable($locale)->value();
            } catch (\DomainException $e) {
                $errors['locale'] = $e->getMessage();
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $tagValue = null;
        if ($tag !== null && trim($tag) !== '') {
            try {
                $tagValue = PostTag::fromString($tag)->value();
            } catch (\DomainException) {
                return null;
            }
        }

        return new PostCriteria(
            now: $now,
            kind: $postKind,
            status: PostStatus::Published,
            locale: $localeValue,
            tag: $tagValue,
            categoryIds: $categoryIds,
            order: PostCriteria::ORDER_PUBLISHED,
        );
    }

    private static function xml(): \XMLWriter
    {
        $x = new \XMLWriter();
        $x->openMemory();
        $x->setIndent(true);
        $x->setIndentString('  ');
        $x->startDocument('1.0', 'UTF-8');

        return $x;
    }

    private static function atomLink(\XMLWriter $x, string $href, string $rel, string $type): void
    {
        $x->startElement('link');
        $x->writeAttribute('href', $href);
        $x->writeAttribute('rel', $rel);
        $x->writeAttribute('type', $type);
        $x->endElement();
    }

    private static function rfc822(?string $atom): string
    {
        return $atom === null ? '' : (new \DateTimeImmutable($atom))->format(DATE_RSS);
    }

    private static function imageType(string $url): string
    {
        return match (strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION))) {
            'png'         => 'image/png',
            'gif'         => 'image/gif',
            'webp'        => 'image/webp',
            'avif'        => 'image/avif',
            'svg'         => 'image/svg+xml',
            default       => 'image/jpeg',
        };
    }
}
