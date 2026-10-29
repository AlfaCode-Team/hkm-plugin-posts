<?php

declare(strict_types=1);

namespace Plugins\Posts\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use Plugins\Posts\API\Contracts\PostServiceContract;

/**
 * Counts a read once per reader per window — so a refresh, a back button or a
 * reader with the tab open all day is one view, not fifty.
 *
 * The reader is identified by a hash of their IP and the post — never the raw
 * IP, which is personal data the view counter has no business keeping. With
 * no CachePort bound every request counts (there is nowhere to remember the
 * reader); the rate-limit filter on the route still caps a flood.
 */
final class PostViewCounter
{
    public function __construct(
        private readonly PostServiceContract $posts,
        private readonly ?CachePort $cache,
        private readonly int $dedupeSeconds,
    ) {
    }

    /** True when this read was counted. */
    public function record(string $postId, ?string $ip, \DateTimeImmutable $now): bool
    {
        $postId = strtoupper(trim($postId));

        if ($this->cache !== null && $this->dedupeSeconds > 0) {
            $key = 'posts:view:' . hash('sha256', $postId . '|' . (string) $ip);

            try {
                if ($this->cache->has($key)) {
                    return false;
                }
                $this->cache->set($key, 1, $this->dedupeSeconds);
            } catch (\Throwable) {
                // A cache outage degrades to counting every read — never to an error.
            }
        }

        return $this->posts->recordView($postId, $now);
    }
}
