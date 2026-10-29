<?php

declare(strict_types=1);

namespace Plugins\Posts\Infrastructure\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use Plugins\Posts\API\DTO\SiteInfo;
use Plugins\Posts\Application\Support\PostsConfig;

/**
 * What every controller of this plugin needs besides its service: the clock,
 * the configuration, and the site as seen from the current request.
 */
final readonly class HttpContext
{
    public function __construct(
        public ClockPort $clock,
        public PostsConfig $config,
    ) {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }

    /**
     * The site, with its base URL from `Request::site()` — the host the entry
     * point already validated against the project's domains — so a feed never
     * links to a host a caller put in a header.
     */
    public function site(Request $request): SiteInfo
    {
        return new SiteInfo(
            name: $this->config->siteName,
            baseUrl: $request->site()->base(),
            description: $this->config->siteDescription,
            logoUrl: $this->config->siteLogo,
            twitterHandle: $this->config->twitterHandle,
            defaultLocale: $this->config->defaultLocale,
        );
    }

    /** A query value as a trimmed string, or null when absent/empty. */
    public static function q(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /** A query value as an int, or null when absent / not a whole number. */
    public static function qInt(Request $request, string $key): ?int
    {
        $value = self::q($request, $key);

        return $value !== null && preg_match('/^-?\d{1,9}$/', $value) === 1 ? (int) $value : null;
    }
}
