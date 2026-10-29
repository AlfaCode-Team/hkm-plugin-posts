<?php

declare(strict_types=1);

namespace Plugins\Posts\Application\Support;

/**
 * PostsConfig — every setting this plugin reads, in one place.
 *
 * Built from a lookup function so tests pass an array and production passes
 * the kernel's `env()` helper. EVERY key read here is declared in
 * module.json `config[]` — the boot fails on an undeclared one, and
 * `hkm plugins enable` seeds the declared defaults into the project's .env.
 */
final readonly class PostsConfig
{
    /** @param list<string> $newsKinds */
    public function __construct(
        public PostPaths $paths,
        public string $previewSecret,
        public int $maxRevisions,
        public string $writePermission,
        public string $publishPermission,
        public string $managePermission,
        public array $newsKinds,
        public bool $fullContentFeeds,
        public string $siteName,
        public string $siteDescription,
        public ?string $siteLogo,
        public ?string $twitterHandle,
        public string $defaultLocale,
        public int $viewDedupeSeconds,
    ) {
    }

    /** @param callable(string): mixed $env Returns null/false for an unset key. */
    public static function from(callable $env): self
    {
        $get = static function (string $key, string $default = '', bool $allowEmpty = false) use ($env): string {
            $value = $env($key);
            if ($value === null || $value === false) {
                return $default;
            }

            $value = trim((string) $value);

            return $value === '' && !$allowEmpty ? $default : $value;
        };

        $bool = static fn(string $key, bool $default): bool =>
            in_array(strtolower($get($key, $default ? 'true' : 'false')), ['1', 'true', 'yes', 'on'], true);

        $secret = $get('POSTS_PREVIEW_SECRET');
        if ($secret === '') {
            // Derive rather than reuse: a token signed with APP_KEY itself
            // would be valid wherever else APP_KEY signs things.
            $appKey = $get('APP_KEY');
            $secret = $appKey === '' ? '' : hash_hmac('sha256', 'hkm-plugin-posts/preview', $appKey);
        }

        $logo    = $get('POSTS_SITE_LOGO');
        $twitter = $get('POSTS_TWITTER_HANDLE');

        return new self(
            paths: PostPaths::fromConfig($get('POSTS_PATHS')),
            previewSecret: $secret,
            maxRevisions: max(1, (int) $get('POSTS_MAX_REVISIONS', '50')),
            // allowEmpty: an EMPTY permission means "the project authorises
            // this itself" and must not silently turn back into the default.
            writePermission: $get('POSTS_WRITE_PERMISSION', 'posts:write', allowEmpty: true),
            publishPermission: $get('POSTS_PUBLISH_PERMISSION', 'posts:publish', allowEmpty: true),
            managePermission: $get('POSTS_MANAGE_PERMISSION', 'posts:manage', allowEmpty: true),
            newsKinds: array_values(array_filter(array_map('trim', explode(',', strtolower($get('POSTS_NEWS_KINDS', 'press,article')))))),
            fullContentFeeds: $bool('POSTS_FEED_FULL_CONTENT', true),
            siteName: $get('POSTS_SITE_NAME', $get('APP_NAME', 'Blog')),
            siteDescription: $get('POSTS_SITE_DESCRIPTION'),
            siteLogo: $logo === '' ? null : $logo,
            twitterHandle: $twitter === '' ? null : $twitter,
            defaultLocale: $get('POSTS_DEFAULT_LOCALE', 'en'),
            viewDedupeSeconds: max(0, (int) $get('POSTS_VIEW_DEDUPE_SECONDS', '1800')),
        );
    }

    /** The kernel's env() helper, or getenv() outside a kernel (tooling). */
    public static function fromEnvironment(): self
    {
        return self::from(static fn(string $key): mixed => function_exists('env') ? env($key) : ($_ENV[$key] ?? null));
    }
}
