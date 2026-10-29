<?php

declare(strict_types=1);

namespace Plugins\Posts\Infrastructure\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;

/**
 * Route filter `posts.rate_limit:{perMinute}` — a fixed one-minute window per
 * client IP, kept in CachePort (same shape as Payment's status limiter).
 *
 * With no CachePort bound there is nothing to count in and the stage passes
 * through: rate limiting then belongs to the edge (nginx `limit_req`). A cache
 * outage also passes through — it must not take the public site down.
 */
final class PostsRateLimitStage implements HttpStageContract
{
    public const string ALIAS = 'posts.rate_limit';

    private const int DEFAULT_PER_MINUTE = 60;

    public function handle(Request $request, callable $next): Response
    {
        $limit     = (int) (($request->attribute('filter_args')[self::ALIAS][0] ?? null) ?? self::DEFAULT_PER_MINUTE);
        $container = $request->container();

        if ($limit <= 0 || $container === null || !$container->has(CachePort::class)) {
            return $next($request);
        }

        /** @var CachePort $cache */
        $cache  = $container->make(CachePort::class);
        $now    = $container->has(ClockPort::class) ? $container->make(ClockPort::class)->timestamp() : time();
        $window = intdiv($now, 60);
        // Keyed on the ROUTE, never the concrete path: with the path, every
        // fresh {token} or {id} got its own empty bucket, so a guesser rotating
        // preview tokens was never limited and every guess minted a new cache
        // key. One bucket per (route, client) per minute is bounded by the
        // number of routes, not by what a caller chooses to send.
        $entry  = $request->attribute('route_entry');
        $route  = \is_array($entry) && \is_string($entry['handler'] ?? null) ? $entry['handler'] : $request->path();
        $key    = 'posts:rl:' . hash('sha256', $route . '|' . $request->ip()) . ':' . $window;

        try {
            if (!$cache->has($key)) {
                $cache->set($key, 0, 120);
            }
            $count = $cache->increment($key);
        } catch (\Throwable) {
            return $next($request);
        }

        if ($count > $limit) {
            return Response::tooManyRequests('Too many requests. Try again in a minute.', retryAfter: 60 - ($now % 60));
        }

        return $next($request);
    }
}
