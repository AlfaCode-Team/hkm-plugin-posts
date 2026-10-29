<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Posts\Feature;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Kernel;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;
use PHPUnit\Framework\TestCase;
use Plugins\I18n\Provider as I18nProvider;
use Plugins\Pageflow\Provider as PageflowProvider;
use Plugins\Posts\Provider;
use Plugins\ViteManifest\Provider as ViteManifestProvider;
use Tests\Unit\Plugins\Posts\Support\Kernel\ArrayCache;
use Tests\Unit\Plugins\Posts\Support\Kernel\DatabaseManagementStub;
use Tests\Unit\Plugins\Posts\Support\Kernel\PassThroughLayer;
use Tests\Unit\Plugins\Posts\Support\Kernel\TestResponse;
use Tests\Unit\Plugins\Posts\Support\SqliteDatabasePort;

/**
 * The plugin booted in a REAL kernel: module.json compiled by the real boot
 * pipeline, routes matched by the real router, filters registered by the real
 * Provider::boot(), controllers resolved by the real scoped container, errors
 * shaped by the real ErrorStage. Only the database (SQLite, built by the
 * plugin's migration) and the cache are stand-ins.
 *
 * This is the test that catches what unit tests cannot: a route the compiler
 * rejects, a controller signature the stage does not call, a binding that
 * resolves in a test but not under scope isolation.
 */
final class PostsHttpTest extends TestCase
{
    private string $root;
    private SqliteDatabasePort $db;
    private ArrayCache $cache;
    private Kernel $kernel;

    /** @var array<string, ?string> */
    private array $previousEnv = [];
    private ?string $previousBase = null;
    private ?string $previousProject = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/posts-feature-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/var/cache/manifests', 0775, true);

        $this->previousBase    = Paths::base();
        $this->previousProject = Paths::project();

        $this->setEnv('BOOT_CACHE', '0');
        $this->setEnv('APP_KEY', 'feature-test-key-0123456789abcdef');
        $this->setEnv('POSTS_SITE_NAME', 'Feature Site');

        $this->db    = new SqliteDatabasePort();
        $this->cache = new ArrayCache();

        Paths::setBase($this->root);
        Paths::setProject($this->root);

        $this->kernel = Kernel::configure()
            ->withBasePath($this->root)
            ->withProjectPath($this->root)
            ->withPorts([DatabasePort::class => $this->db, CachePort::class => $this->cache])
            ->withSecurity([new PassThroughLayer()])
            // Posts' own admin PAGE routes (/admin/posts…) declare
            // `requires: ["http.pageflow"]` (module.json), so the real boot
            // this test exercises needs a real module solving that domain —
            // and Pageflow's OWN module.json requires 'vite.manifest' +
            // 'i18n.translation' in turn. Same three-module shape as
            // hkm-plugin-user's AdminUiTest, which has the identical route
            // shape.
            ->withModules([DatabaseManagementStub::class, I18nProvider::class, ViteManifestProvider::class, PageflowProvider::class, Provider::class])
            // Pageflow's OWN routes (/pageflow/csrf, /pageflow/stream) name the
            // `auth`/`throttle` filters SecurityFilters registers. This suite
            // never calls them, so drop them rather than relax
            // ROUTE_STRICT_FILTERS — strict mode is what proves Posts' own
            // aliases (`posts.editor`, `posts.rate_limit`) are registered.
            ->withRoutePolicy(['http.pageflow'])
            ->build();
    }

    protected function tearDown(): void
    {
        Paths::setBase((string) $this->previousBase);
        Paths::setProject($this->previousProject);

        foreach ($this->previousEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->root);
    }

    private function setEnv(string $key, string $value): void
    {
        if (!array_key_exists($key, $this->previousEnv)) {
            $this->previousEnv[$key] = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string> $headers extra $_SERVER-style entries (e.g. ['HTTP_X_PAGEFLOW' => 'true']
     *        to force PageflowResponder's JSON page-object reply instead of the HTML shell).
     */
    private function call(string $method, string $uri, ?array $json = null, ?Identity $as = null, array $headers = []): TestResponse
    {
        $request = Request::create(
            'https://example.test' . $uri,
            $method,
            server: ($json === null ? [] : ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json']) + $headers,
            content: $json === null ? null : json_encode($json, JSON_THROW_ON_ERROR),
        );

        if ($as !== null) {
            $request = $request->withIdentity($as);
        }

        return new TestResponse($this->kernel->http()->handle($request));
    }

    private static function editor(): Identity
    {
        return new Identity('erin', 'tenant-1', [], ['posts:manage'], 'session', fullName: 'Erin Editor');
    }

    private static function contributor(): Identity
    {
        return new Identity('alice', 'tenant-1', [], ['posts:write'], 'session');
    }

    /** Create a post through the admin API; returns its data. */
    private function create(array $input): array
    {
        $response = $this->call('POST', '/api/admin/posts', $input, self::editor());
        $response->assertCreated();

        return $response->json()['data'];
    }

    // ── boot ─────────────────────────────────────────────────────────────

    public function test_the_routes_compile_with_their_names(): void
    {
        $names = require $this->root . '/var/cache/manifests/route-names.php';

        foreach (['posts.index', 'posts.show', 'posts.feed', 'posts.feed.kind', 'posts.sitemap', 'posts.sitemap.news', 'posts.admin.store', 'posts.admin.revision.restore', 'posts.admin.categories.update'] as $name) {
            $this->assertArrayHasKey($name, $names, "route [{$name}] did not compile");
        }
    }

    // ── public API ───────────────────────────────────────────────────────

    public function test_a_published_post_is_readable_and_a_draft_is_not(): void
    {
        $post = $this->create(['title' => 'Hello World', 'body' => "## Intro\n\nHi", 'published' => true, 'tags' => ['news']]);
        $this->create(['title' => 'Secret draft', 'published' => false]);

        $list = $this->call('GET', '/api/posts')->assertOk();
        $this->assertSame(['Hello World'], array_column($list->json()['data'], 'title'));
        $this->assertSame(1, $list->json()['meta']['total']);
        $this->assertArrayNotHasKey('body', $list->json()['data'][0]);
        $list->assertHeader('Cache-Control', 'max-age=60, public');

        $show = $this->call('GET', '/api/posts/blog/hello-world')->assertOk()->json()['data'];
        $this->assertSame($post['id'], $show['post']['id']);
        $this->assertSame('<h2 id="intro">Intro</h2>' . "\n" . '<p>Hi</p>', $show['post']['bodyHtml']);
        $this->assertArrayNotHasKey('body', $show['post'], 'the public never gets the source');

        $this->call('GET', '/api/posts/blog/secret-draft')->assertNotFound();
        $this->call('GET', '/api/posts/podcast/hello-world')->assertNotFound();
    }

    public function test_a_renamed_post_answers_301_at_its_old_address(): void
    {
        $post = $this->create(['title' => 'Old name', 'published' => true]);
        $this->call('PUT', '/api/admin/posts/' . $post['id'], ['title' => 'Old name', 'slug' => 'new-name', 'published' => true], self::editor())->assertOk();

        $this->call('GET', '/api/posts/blog/old-name')->assertStatus(301)->assertRedirect('/api/posts/blog/new-name');
    }

    public function test_tags_categories_archive_and_popular_respond(): void
    {
        $this->create(['title' => 'One', 'published' => true, 'tags' => ['news']]);

        $this->assertSame([['tag' => 'news', 'count' => 1]], $this->call('GET', '/api/posts/tags')->assertOk()->json()['data']);
        $this->call('GET', '/api/posts/categories')->assertOk();
        $this->assertCount(1, $this->call('GET', '/api/posts/archive')->assertOk()->json()['data']);
        $this->assertCount(1, $this->call('GET', '/api/posts/popular')->assertOk()->json()['data']);
    }

    public function test_a_bad_query_is_a_422_with_field_errors(): void
    {
        $this->call('GET', '/api/posts?kind=podcast&month=13&year=2026')->assertUnprocessable()->assertInvalid(['kind', 'month']);
    }

    public function test_the_view_beacon_counts_a_reader_once(): void
    {
        $post = $this->create(['title' => 'Counted', 'published' => true]);

        $this->call('POST', '/api/posts/views/' . $post['id'])->assertNoContent();
        $this->call('POST', '/api/posts/views/' . $post['id'])->assertNoContent();

        $this->assertSame(1, $this->call('GET', '/api/admin/posts/' . $post['id'], as: self::editor())->json()['data']['viewCount']);
    }

    // ── feeds & sitemaps ─────────────────────────────────────────────────

    public function test_rotating_preview_tokens_does_not_escape_the_rate_limit(): void
    {
        // The limit is per (route, client) per minute. Start clear of a minute
        // boundary so all 31 requests land in one window.
        if (time() % 60 >= 55) {
            sleep(61 - time() % 60);
        }

        for ($i = 0; $i < 30; $i++) {
            $this->call('GET', '/api/posts/preview/' . bin2hex(random_bytes(16)))->assertNotFound();
        }

        // A 31st, never-seen token still hits the same bucket.
        $this->call('GET', '/api/posts/preview/' . bin2hex(random_bytes(16)))->assertStatus(429);
    }

    public function test_feeds_and_sitemaps_are_served_with_their_media_types(): void
    {
        $this->create(['title' => 'Feed me', 'published' => true, 'kind' => 'press']);

        $rss = $this->call('GET', '/feeds/posts/rss')->assertOk();
        $this->assertStringStartsWith('application/rss+xml', (string) $rss->header('Content-Type'));
        $this->assertSame('Feed me', (string) simplexml_load_string($rss->body())->channel->item[0]->title);
        $this->assertSame('https://example.test/press/feed-me', (string) simplexml_load_string($rss->body())->channel->item[0]->link);

        $this->assertStringStartsWith('application/atom+xml', (string) $this->call('GET', '/feeds/press/atom')->assertOk()->header('Content-Type'));
        $this->assertSame('Feed me', json_decode($this->call('GET', '/feeds/posts/json')->assertOk()->body(), true)['items'][0]['title']);
        $this->call('GET', '/feeds/posts/pdf')->assertNotFound();

        $this->assertStringContainsString('<loc>https://example.test/press/feed-me</loc>', $this->call('GET', '/sitemaps/posts')->assertOk()->body());
        $this->assertStringContainsString('<news:title>Feed me</news:title>', $this->call('GET', '/sitemaps/posts-news')->assertOk()->body());
    }

    // ── admin API ────────────────────────────────────────────────────────

    public function test_an_unchanged_public_response_revalidates_as_a_304(): void
    {
        $this->create(['title' => 'Cache me', 'published' => true]);

        foreach (['/feeds/posts/rss', '/sitemaps/posts', '/api/posts', '/api/posts/blog/cache-me'] as $uri) {
            $first = $this->call('GET', $uri)->assertOk();
            $etag  = (string) $first->header('ETag');
            $this->assertMatchesRegularExpression('#^W/"[0-9a-f]{32}"$#', $etag, $uri);

            $again = $this->call('GET', $uri, headers: ['HTTP_IF_NONE_MATCH' => $etag])->assertStatus(304);
            $this->assertSame('', $again->body(), $uri);
            $this->assertSame($etag, $again->header('ETag'), $uri);
            $this->assertNotNull($again->header('Cache-Control'), $uri);

            $this->call('GET', $uri, headers: ['HTTP_IF_NONE_MATCH' => 'W/"stale"'])->assertOk();
        }

        // A change to the content is a new tag, so the old one no longer matches.
        $before = (string) $this->call('GET', '/api/posts')->header('ETag');
        $this->create(['title' => 'Newer', 'published' => true]);
        $this->call('GET', '/api/posts', headers: ['HTTP_IF_NONE_MATCH' => $before])->assertOk();

        // Private and non-200 answers are never tagged.
        $this->call('GET', '/api/posts/blog/no-such-post')->assertNotFound()->assertHeaderMissing('ETag');
        $this->call('GET', '/api/admin/posts', as: self::editor())->assertOk()->assertHeaderMissing('ETag');
    }

    public function test_the_admin_api_is_closed_to_guests_and_to_users_without_a_posts_permission(): void
    {
        $this->call('GET', '/api/admin/posts')->assertUnauthorized();
        $this->call('GET', '/api/admin/posts', as: new Identity('u', 't', [], ['other'], 'session'))->assertForbidden();
        $this->call('GET', '/api/admin/posts', as: self::contributor())->assertOk();
    }

    // ── admin UI (Pageflow pages) ───────────────────────────────────────────

    /**
     * The server half of ui/admin/Pages/Posts/*.tsx — same `posts.editor` door
     * as the JSON API, same real boot/route-compiler/dependency-graph path.
     * Requested with X-Pageflow so PageflowResponder answers the JSON page
     * object (component + props) rather than the HTML shell.
     */
    public function test_admin_pages_render_behind_the_editor_door(): void
    {
        $pageflow = ['HTTP_X_PAGEFLOW' => 'true'];

        $this->call('GET', '/admin/posts', headers: $pageflow)->assertUnauthorized();
        $this->call('GET', '/admin/posts', as: new Identity('u', 't', [], ['other'], 'session'), headers: $pageflow)->assertForbidden();

        $post = $this->create(['title' => 'Rendered via the page route', 'body' => 'hi']);

        $index = $this->call('GET', '/admin/posts', as: self::editor(), headers: $pageflow)->assertOk()->json();
        $this->assertSame('Posts/Index', $index['component']);
        $this->assertSame(['blog', 'article', 'press'], $index['props']['capabilities']['kinds']);
        $this->assertSame('Rendered via the page route', $index['props']['initialPage']['data'][0]['title']);

        $create = $this->call('GET', '/admin/posts/create', as: self::editor(), headers: $pageflow)->assertOk()->json();
        $this->assertSame('Posts/Editor', $create['component']);
        $this->assertNull($create['props']['postId']);
        $this->assertNull($create['props']['post']);

        $edit = $this->call('GET', "/admin/posts/{$post['id']}/edit", as: self::editor(), headers: $pageflow)->assertOk()->json();
        $this->assertSame('Posts/Editor', $edit['component']);
        $this->assertSame($post['id'], $edit['props']['postId']);
        $this->assertSame('Rendered via the page route', $edit['props']['post']['title']);
        $this->assertSame('hi', $edit['props']['post']['body'], 'the editor needs the body, unlike a listing row');

        $this->call('GET', '/admin/posts/doesnotexist123/edit', as: self::editor(), headers: $pageflow)->assertNotFound();

        $categories = $this->call('GET', '/admin/posts/categories', as: self::editor(), headers: $pageflow)->assertOk()->json();
        $this->assertSame('Posts/Categories', $categories['component']);
        $this->assertIsArray($categories['props']['categories']);

        $this->assertSame(1, $edit['props']['post']['version'], 'the editor sends this back to detect a concurrent save');

        // The UI tests render these exact page objects. Re-dump them after a
        // props change with:  DUMP_UI_FIXTURES=1 vendor/bin/phpunit --filter editor_door
        if (($_SERVER['DUMP_UI_FIXTURES'] ?? getenv('DUMP_UI_FIXTURES')) === '1') {
            foreach (['posts-index' => $index, 'posts-editor' => $edit, 'posts-categories' => $categories] as $name => $page) {
                file_put_contents(
                    dirname(__DIR__, 2) . "/ui/__fixtures__/{$name}.json",
                    json_encode($page, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n",
                );
            }
        }
    }

    public function test_a_contributor_publishing_gets_a_403_and_a_bad_post_a_422(): void
    {
        $this->call('POST', '/api/admin/posts', ['title' => 'X', 'published' => true], self::contributor())->assertForbidden();
        $this->call('POST', '/api/admin/posts', ['title' => '', 'kind' => 'podcast'], self::editor())->assertUnprocessable()->assertInvalid(['title', 'kind']);
    }

    public function test_the_full_editorial_round_trip(): void
    {
        $post = $this->create(['title' => 'Draft', 'body' => 'v1']);
        $id   = $post['id'];
        $this->assertSame('erin', $post['authorId'], 'bylined to the editor');
        $this->assertSame('Erin Editor', $post['authorName']);

        $this->call('PUT', "/api/admin/posts/{$id}", ['title' => 'Draft', 'body' => 'v2', 'revisionNote' => 'second pass'], self::editor())->assertOk();
        $revisions = $this->call('GET', "/api/admin/posts/{$id}/revisions", as: self::editor())->assertOk()->json()['data'];
        $this->assertSame([2, 1], array_column($revisions, 'number'));
        $this->assertSame('second pass', $revisions[0]['note']);

        $this->assertSame('v1', $this->call('POST', "/api/admin/posts/{$id}/revisions/1/restore", [], self::editor())->assertOk()->json()['data']['body']);

        $link = $this->call('POST', "/api/admin/posts/{$id}/preview-link", ['ttl' => 3600], self::editor())->assertOk()->json()['data'];
        $this->assertStringStartsWith('https://example.test/api/posts/preview/', $link['url']);
        $this->call('GET', '/api/posts/preview/' . $link['token'])->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $copy = $this->call('POST', "/api/admin/posts/{$id}/duplicate", [], self::editor())->assertCreated()->json()['data'];
        $this->assertSame('Draft (copy)', $copy['title']);

        $this->assertSame([$id => 'ok', $copy['id'] => 'ok'], $this->call('POST', '/api/admin/posts/bulk', ['action' => 'publish', 'ids' => [$id, $copy['id']]], self::editor())->assertOk()->json()['data']);
        $this->call('GET', '/api/posts/blog/draft')->assertOk();

        $this->call('DELETE', "/api/admin/posts/{$id}", as: self::editor())->assertNoContent();
        $this->call('GET', '/api/posts/blog/draft')->assertNotFound();
        $this->call('POST', "/api/admin/posts/{$id}/restore", [], self::editor())->assertOk();
        $this->call('DELETE', "/api/admin/posts/{$id}/permanent", as: self::editor())->assertNoContent();
        $this->call('GET', "/api/admin/posts/{$id}", as: self::editor())->assertNotFound();
    }

    public function test_the_public_list_filters_by_byline_author(): void
    {
        $this->create(['title' => 'Jane writes', 'published' => true, 'authorId' => 'jane', 'authorName' => 'Jane']);
        $this->create(['title' => 'Erin writes', 'published' => true]);

        $this->assertSame(['Jane writes'], array_column($this->call('GET', '/api/posts?author=jane')->assertOk()->json()['data'], 'title'));
        // Erin created both rows; only the one credited to her is hers publicly.
        $this->assertSame(['Erin writes'], array_column($this->call('GET', '/api/posts?author=erin')->assertOk()->json()['data'], 'title'));
    }

    public function test_a_stale_save_is_a_409_with_its_error_code(): void
    {
        $post = $this->create(['title' => 'Shared', 'published' => false]);
        $this->assertSame(1, $post['version']);

        $this->call('PUT', '/api/admin/posts/' . $post['id'], ['title' => 'First', 'version' => 1], self::editor())->assertOk();
        $this->call('PUT', '/api/admin/posts/' . $post['id'], ['title' => 'Second', 'version' => 1], self::editor())
            ->assertStatus(409)
            ->assertErrorCode('post.edit_conflict');

        $this->assertSame('First', $this->call('GET', '/api/admin/posts/' . $post['id'], as: self::editor())->json()['data']['title']);
    }

    public function test_admin_helpers_respond(): void
    {
        $this->assertSame(['blog', 'article', 'press'], $this->call('GET', '/api/admin/posts/me', as: self::editor())->assertOk()->json()['data']['kinds']);
        $this->assertSame('cafe-a-kinshasa', $this->call('GET', '/api/admin/posts/slug?title=' . rawurlencode('Café à Kinshasa'), as: self::editor())->assertOk()->json()['data']['slug']);
        $this->assertSame('<p><strong>bold</strong></p>', $this->call('POST', '/api/admin/posts/render', ['body' => '**bold**'], self::editor())->assertOk()->json()['data']['html']);
        $this->assertArrayHasKey('blog', $this->call('GET', '/api/admin/posts/stats', as: self::editor())->assertOk()->json()['data']);
        $this->call('GET', '/api/admin/posts/stats', as: self::contributor())->assertForbidden();

        $this->create(['title' => 'Counted', 'published' => true]);
        $this->assertSame(['draft' => 0, 'scheduled' => 0, 'published' => 1, 'trash' => 0], $this->call('GET', '/api/admin/posts/counts?kind=blog', as: self::editor())->assertOk()->json()['data']);
        $this->call('GET', '/api/admin/posts/counts?kind=nope', as: self::editor())->assertUnprocessable();
        $this->call('GET', '/api/admin/posts/counts')->assertUnauthorized();
    }

    public function test_category_admin_round_trip(): void
    {
        $news = $this->call('POST', '/api/admin/post-categories', ['name' => 'News'], self::editor())->assertCreated()->json()['data'];
        $this->call('PUT', '/api/admin/post-categories/' . $news['id'], ['name' => 'Newsroom'], self::editor())->assertOk();
        $this->assertSame(['Newsroom'], array_column($this->call('GET', '/api/admin/post-categories', as: self::contributor())->assertOk()->json()['data'], 'name'));
        $this->call('POST', '/api/admin/post-categories', ['name' => 'X'], self::contributor())->assertForbidden();
        $this->call('DELETE', '/api/admin/post-categories/' . $news['id'], as: self::editor())->assertNoContent();
    }

    // ── CLI ──────────────────────────────────────────────────────────────

    public function test_the_scheduler_command_announces_a_post_whose_time_has_come(): void
    {
        $post = $this->create(['title' => 'Scheduled', 'published' => true, 'publishedAt' => '2020-01-01T00:00:00Z']);
        // Created already due, so the save announced it; make it look unannounced,
        // as a post scheduled yesterday would be when its time arrives.
        $this->db->execute('UPDATE posts SET announced_at = NULL');

        ob_start();
        try {
            $code = $this->kernel->cli()->run(['hkm', 'posts:publish-due']);
        } finally {
            $output = (string) ob_get_clean();
        }

        $this->assertSame(0, $code, $output);
        $this->assertNotNull($this->db->queryOne('SELECT announced_at FROM posts WHERE announced_at IS NOT NULL'));
        $this->assertSame('Scheduled', $post['title']);
    }

    public function test_tenant_flags_without_the_tenancy_plugin_are_refused(): void
    {
        ob_start();
        try {
            $code = $this->kernel->cli()->run(['hkm', 'posts:empty-trash', '--all-tenants']);
        } finally {
            ob_end_clean();
        }

        $this->assertSame(2, $code, 'INVALID: --all-tenants needs Tenancy');
    }
}
