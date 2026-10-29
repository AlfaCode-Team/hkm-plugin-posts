<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Posts\Support;

use PHPUnit\Framework\TestCase;
use Plugins\Posts\API\DTO\PostView;
use Plugins\Posts\Application\Services\PostCategoryService;
use Plugins\Posts\Application\Services\PostService;
use Plugins\Posts\Application\Services\PostSyndicationService;
use Plugins\Posts\Application\Support\PostsConfig;
use Plugins\Posts\Infrastructure\Wiring\PostServiceBundle;

/**
 * Builds the plugin exactly as the Provider does (PostServiceBundle), over a
 * SQLite database created by the plugin's own migration.
 */
abstract class PostsTestCase extends TestCase
{
    protected const string ACTOR = 'user-1';

    protected SqliteDatabasePort $db;
    protected MutableClock $clock;
    protected RecordingPostEvents $events;
    protected PostService $posts;
    protected PostCategoryService $categories;
    protected PostSyndicationService $syndication;

    /** @var array<string, string> */
    protected array $env = ['APP_KEY' => 'test-app-key', 'POSTS_SITE_NAME' => 'Test Site'];

    protected function setUp(): void
    {
        $this->db     = new SqliteDatabasePort();
        $this->clock  = new MutableClock(new \DateTimeImmutable('2026-10-01T09:00:00+00:00'));
        $this->events = new RecordingPostEvents();
        $this->build();
    }

    /** Rebuild the services (e.g. after changing $env) — keeps the database. */
    protected function build(): void
    {
        $bundle = PostServiceBundle::build(
            $this->db,
            $this->clock,
            $this->events,
            PostsConfig::from(fn(string $key): ?string => $this->env[$key] ?? null),
        );

        $this->posts       = $bundle->posts;
        $this->categories  = $bundle->categories;
        $this->syndication = $bundle->syndication;
    }

    protected function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }

    /** @param array<string, mixed> $overrides */
    protected function input(array $overrides = []): array
    {
        return array_merge([
            'kind'            => 'blog',
            'title'           => 'Hello World',
            'subtitle'        => 'A first post',
            'excerpt'         => 'Short teaser.',
            'body'            => "# Hello\n\nThis is the **first** post.",
            'bodyFormat'      => 'markdown',
            'coverImageUrl'   => '/storage/covers/hello.webp',
            'coverImageAlt'   => 'A sunrise',
            'authorId'        => 'user-9',
            'authorName'      => 'Jane Doe',
            'tags'            => ['News', 'Mobile Money'],
            'metaTitle'       => 'Hello World | Blog',
            'metaDescription' => 'The first post.',
            'featured'        => false,
            'published'       => true,
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    protected function make(array $overrides = []): PostView
    {
        return $this->posts->create($this->input($overrides), self::ACTOR);
    }

    /** @param list<PostView> $views @return list<string> */
    protected static function titles(array $views): array
    {
        return array_map(static fn(PostView $v): string => $v->title, $views);
    }
}
