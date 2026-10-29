<?php

declare(strict_types=1);

namespace Plugins\Posts\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Posts\API\Contracts\PostSyndicationContract;
use Plugins\Posts\Infrastructure\Http\HttpContext;

/**
 * Feeds and sitemaps. Paths carry NO file extension (`/feeds/posts/rss`, not
 * `/feed.xml`): an edge that serves asset extensions statically would never
 * hand a `.xml` URL to PHP, and the feed would 404 in production only.
 */
final class FeedController
{
    public function __construct(
        private readonly PostSyndicationContract $syndication,
        private readonly HttpContext $http,
    ) {
    }

    /** GET /feeds/posts/{format}?kind&category&tag&locale */
    public function all(Request $request, string $format): Response
    {
        return $this->feed($request, $format, HttpContext::q($request, 'kind'));
    }

    /** GET /feeds/{kind}/{format}?category&tag&locale */
    public function kind(Request $request, string $kind, string $format): Response
    {
        return $this->feed($request, $format, $kind);
    }

    /** GET /sitemaps/posts?kind&locale */
    public function sitemap(Request $request): Response
    {
        return self::xml($this->syndication->sitemap(
            $this->http->now(),
            $this->http->site($request),
            HttpContext::q($request, 'kind'),
            $request->query('locale') === null ? null : (string) $request->query('locale'),
        ), 'application/xml; charset=utf-8');
    }

    /** GET /sitemaps/posts-news — Google News. */
    public function news(Request $request): Response
    {
        return self::xml($this->syndication->newsSitemap($this->http->now(), $this->http->site($request)), 'application/xml; charset=utf-8', 600);
    }

    private function feed(Request $request, string $format, ?string $kind): Response
    {
        $site = $this->http->site($request);
        $self = $request->site()->to(ltrim($request->path(), '/'), array_filter([
            'kind'     => $request->query('kind'),
            'category' => $request->query('category'),
            'tag'      => $request->query('tag'),
            'locale'   => $request->query('locale'),
        ], static fn(mixed $v): bool => is_scalar($v) && $v !== ''));

        $body = $this->syndication->feed(
            $format,
            $this->http->now(),
            $site,
            $self,
            $kind,
            HttpContext::q($request, 'category'),
            HttpContext::q($request, 'tag'),
            $request->query('locale') === null ? null : (string) $request->query('locale'),
        );

        return self::xml($body, $this->syndication->feedContentType($format));
    }

    private static function xml(string $body, string $type, int $maxAge = 300): Response
    {
        return Response::text($body)
            ->withHeader('Content-Type', $type)
            ->withHeader('Cache-Control', "public, max-age={$maxAge}");
    }
}
