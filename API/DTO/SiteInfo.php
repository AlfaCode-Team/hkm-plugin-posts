<?php

declare(strict_types=1);

namespace Plugins\Posts\API\DTO;

/**
 * SiteInfo — what syndication needs to know about the site that is not a post:
 * its name, its absolute base URL (so a post's `path` becomes a link that works
 * outside the site), and the publisher identity search engines ask for.
 *
 * Build `baseUrl` from the VALIDATED request host (`$request->site()->base()`)
 * or APP_URL — never from a raw Host header, or a feed reader can be handed
 * links to someone else's domain.
 */
final readonly class SiteInfo
{
    public string $baseUrl;

    public function __construct(
        public string $name,
        string $baseUrl,
        public string $description = '',
        public ?string $logoUrl = null,
        public ?string $twitterHandle = null,
        /** BCP 47; used for posts in the default ('') locale. */
        public string $defaultLocale = 'en',
    ) {
        if (preg_match('#^https?://[^/\s]+#i', $baseUrl) !== 1) {
            throw new \InvalidArgumentException('SiteInfo baseUrl must be an absolute http(s) URL.');
        }

        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /** `/blog/x` → `https://example.com/blog/x`; an absolute URL is returned unchanged. */
    public function absolute(string $pathOrUrl): string
    {
        if (preg_match('#^https?://#i', $pathOrUrl) === 1) {
            return $pathOrUrl;
        }

        return $this->baseUrl . '/' . ltrim($pathOrUrl, '/');
    }

    public function languageOf(string $locale): string
    {
        return $locale === '' ? $this->defaultLocale : $locale;
    }
}
