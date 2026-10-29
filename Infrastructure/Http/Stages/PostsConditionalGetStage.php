<?php

declare(strict_types=1);

namespace Plugins\Posts\Infrastructure\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;

/**
 * Route filter `posts.cache` — conditional GET for the public read routes.
 *
 * A publicly cacheable 200 gets a weak ETag hashed from its body; a request
 * whose If-None-Match already holds that tag gets an empty 304 instead. Feed
 * readers and crawlers revalidate this way on every poll, so an unchanged
 * feed or sitemap costs a few header bytes instead of the whole document.
 *
 * Self-gating: anything that is not a GET/HEAD 200 marked `public` (a 404, a
 * 301, the `no-store` preview, the view beacon's 204) passes through
 * untouched. The body is still built; this saves the transfer and the
 * client's parse, not the query. Holds no state, so one instance serves every
 * request under OpenSwoole.
 */
final class PostsConditionalGetStage implements HttpStageContract
{
    public const string ALIAS = 'posts.cache';

    public function handle(Request $request, callable $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (!\in_array($request->method(), ['GET', 'HEAD'], true) || $response->status() !== 200) {
            return $response;
        }

        $headers      = array_change_key_case($response->headers(), CASE_LOWER);
        $cacheControl = $headers['cache-control'] ?? '';
        if (!str_contains($cacheControl, 'public')) {
            return $response;
        }

        $etag     = 'W/"' . hash('xxh128', $response->body()) . '"';
        $response = $response->withHeader('ETag', $etag);

        if (!self::matches((string) $request->header('If-None-Match', ''), $etag)) {
            return $response;
        }

        // RFC 9110 §15.4.5: a 304 repeats the validators and caching headers
        // a 200 would have carried, and nothing else.
        $notModified = Response::empty(304)->withHeader('ETag', $etag)->withHeader('Cache-Control', $cacheControl);
        if (isset($headers['vary'])) {
            $notModified = $notModified->withHeader('Vary', $headers['vary']);
        }

        return $notModified;
    }

    /** Weak comparison (RFC 9110 §8.8.3.2): `W/` is ignored on both sides. */
    private static function matches(string $ifNoneMatch, string $etag): bool
    {
        if (trim($ifNoneMatch) === '*') {
            return true;
        }

        $opaque = substr($etag, 2);
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);
            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }
            if ($candidate !== '' && hash_equals($opaque, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
