<?php

declare(strict_types=1);

namespace Plugins\Posts\Application\Support;

use Plugins\Posts\Domain\ValueObjects\PostKind;

/**
 * PostPaths — where each kind of post lives on the site.
 *
 * Configured with `POSTS_PATHS`, a `kind=pattern` list separated by `;`:
 *
 *     blog=/blog/{slug};article=/articles/{slug};press=/press/{slug}   (default)
 *     blog=/{locale}/news/{slug}                                        (custom)
 *
 * A pattern may use `{slug}`, `{kind}` and `{locale}`. A post with a locale
 * whose pattern does not mention `{locale}` gets `/{locale}` in front, so a
 * multilingual site works without any configuration and a single-language
 * site never sees a locale in its URLs.
 *
 * These are the addresses feeds, sitemaps, structured data and events point
 * at — the HOST owns the routes that serve them; keep the two in step.
 */
final readonly class PostPaths
{
    /** @var array<string, string> */
    private array $patterns;

    /** @param array<string, string> $patterns kind => pattern; kinds left out use the default. */
    public function __construct(array $patterns = [])
    {
        $defaults = [
            PostKind::Blog->value    => '/blog/{slug}',
            PostKind::Article->value => '/articles/{slug}',
            PostKind::Press->value   => '/press/{slug}',
        ];

        foreach ($patterns as $kind => $pattern) {
            if (PostKind::tryFrom($kind) === null) {
                throw new \InvalidArgumentException("POSTS_PATHS names an unknown kind [{$kind}].");
            }
            if (!str_starts_with($pattern, '/') || !str_contains($pattern, '{slug}')) {
                throw new \InvalidArgumentException("POSTS_PATHS pattern for [{$kind}] must start with '/' and contain {slug}.");
            }
        }

        $this->patterns = array_merge($defaults, $patterns);
    }

    /** Parse the `POSTS_PATHS` env format; empty → defaults. */
    public static function fromConfig(string $config): self
    {
        $patterns = [];
        foreach (explode(';', $config) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }
            [$kind, $pattern] = array_map('trim', explode('=', $pair, 2) + [1 => '']);
            $patterns[strtolower($kind)] = $pattern;
        }

        return new self($patterns);
    }

    public function pathFor(string $kind, string $slug, string $locale = ''): string
    {
        $pattern = $this->patterns[$kind] ?? '/' . $kind . '/{slug}';

        $path = strtr($pattern, [
            '{slug}'   => rawurlencode($slug),
            '{kind}'   => rawurlencode($kind),
            '{locale}' => rawurlencode($locale),
        ]);

        if ($locale !== '' && !str_contains($pattern, '{locale}')) {
            $path = '/' . rawurlencode($locale) . $path;
        }

        // An empty {locale} segment would leave `//`.
        return (string) preg_replace('#/{2,}#', '/', $path);
    }

    /** The listing page of a kind — the pattern up to its first placeholder. */
    public function indexFor(string $kind, string $locale = ''): string
    {
        $path = rtrim(strstr($this->pathFor($kind, "\u{0}", $locale), rawurlencode("\u{0}"), true) ?: '/', '/');

        return $path === '' ? '/' : $path;
    }
}
