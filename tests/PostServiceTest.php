<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Posts;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use Plugins\Posts\Application\Services\PostService;
use Plugins\Posts\Infrastructure\Persistence\PostRepository;
use Tests\Unit\Plugins\Posts\Support\PostsTestCase;

/**
 * Create / read / update / list — end to end through the real repositories,
 * on a SQLite database built by the plugin's own migration.
 */
#[CoversClass(PostService::class)]
#[CoversClass(PostRepository::class)]
final class PostServiceTest extends PostsTestCase
{
    // ── create / find ────────────────────────────────────────────────────

    public function test_create_returns_and_persists_a_full_view(): void
    {
        $view = $this->make();

        $this->assertSame('blog', $view->kind);
        $this->assertSame('', $view->locale);
        $this->assertSame('hello-world', $view->slug);
        $this->assertSame('/blog/hello-world', $view->path);
        $this->assertSame(['news', 'mobile-money'], $view->tags);
        $this->assertSame('published', $view->status);
        $this->assertSame('2026-10-01T09:00:00+00:00', $view->publishedAt);
        $this->assertSame('Short teaser.', $view->summary);
        $this->assertSame(1, $view->readingMinutes);
        $this->assertTrue($view->commentsEnabled, 'comments default to on');
        $this->assertStringContainsString('<strong>first</strong>', (string) $view->bodyHtml);
        $this->assertSame([], $view->toc, 'an h1 is not a TOC entry');

        $this->assertEquals($view, $this->posts->find($view->id), 'what was returned is exactly what was stored');
    }

    public function test_a_press_release_keeps_its_press_fields(): void
    {
        $view = $this->make([
            'kind'         => 'press',
            'title'        => 'Company raises Series A',
            'dateline'     => 'KAMPALA, Uganda',
            'contact'      => "Press office\npress@example.test",
            'sourceName'   => 'Daily Monitor',
            'sourceUrl'    => 'https://monitor.test/story',
            'canonicalUrl' => 'https://monitor.test/story',
            'noindex'      => true,
        ]);

        $found = $this->posts->find($view->id);
        $this->assertSame('/press/company-raises-series-a', $found?->path);
        $this->assertSame('KAMPALA, Uganda', $found?->dateline);
        $this->assertSame("Press office\npress@example.test", $found?->contact);
        $this->assertSame('Daily Monitor', $found?->sourceName);
        $this->assertSame('https://monitor.test/story', $found?->canonicalUrl);
        $this->assertTrue($found?->noindex);
    }

    public function test_minimal_input_is_a_blog_draft(): void
    {
        $view = $this->posts->create(['title' => 'Just a title'], self::ACTOR);

        $this->assertSame('blog', $view->kind);
        $this->assertSame('draft', $view->status);
        $this->assertNull($view->publishedAt);
        $this->assertSame('markdown', $view->bodyFormat);
        $this->assertSame([], $view->tags);
        $this->assertSame('', $view->summary);
        $this->assertSame('', $view->bodyHtml);
    }

    public function test_summary_and_reading_time_are_stored_so_listings_show_them_without_bodies(): void
    {
        $this->make(['excerpt' => null, 'body' => trim(str_repeat('lorem ipsum ', 300))]);

        $card = $this->posts->list()->items[0];

        $this->assertNull($card->body, 'a listing does not load bodies');
        $this->assertStringEndsWith('…', $card->summary);
        $this->assertLessThanOrEqual(201, mb_strlen($card->summary));
        $this->assertSame(3, $card->readingMinutes, '600 words at 200 a minute');
    }

    public function test_create_collects_every_field_error_at_once(): void
    {
        try {
            $this->make([
                'kind'          => 'podcast',
                'title'         => '',
                'slug'          => 'Not A Slug',
                'locale'        => 'english!',
                'bodyFormat'    => 'rtf',
                'coverImageUrl' => 'javascript:alert(1)',
                'sourceUrl'     => '/relative',
                'canonicalUrl'  => 'ftp://x',
                'publishedAt'   => 'tomorrow',
                'metaTitle'     => str_repeat('m', 161),
                'tags'          => ['ok', '!!!'],
                'categories'    => ['not-an-id'],
            ]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            foreach (['kind', 'title', 'slug', 'locale', 'bodyFormat', 'coverImageUrl', 'sourceUrl', 'canonicalUrl', 'publishedAt', 'metaTitle', 'tags.1', 'categories.0'] as $key) {
                $this->assertArrayHasKey($key, $e->errors, "missing error for {$key}");
            }
        }

        $this->assertSame(0, $this->posts->list()->total, 'nothing was written');
    }

    public function test_an_unknown_category_id_is_a_field_error(): void
    {
        try {
            $this->make(['categories' => ['01J00000000000000000000000']]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('categories.0', $e->errors);
        }
    }

    public function test_a_list_in_a_text_field_is_a_field_error_not_a_warning(): void
    {
        try {
            $this->make(['title' => ['x'], 'body' => ['y']]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('title', $e->errors);
            $this->assertArrayHasKey('body', $e->errors);
        }
    }

    public function test_html_bodies_are_stored_raw_but_rendered_safe(): void
    {
        $view = $this->make(['body' => '<p onclick="x()">Hi<script>alert(1)</script></p>', 'bodyFormat' => 'html']);

        $this->assertSame('<p onclick="x()">Hi<script>alert(1)</script></p>', $view->body, 'the source is kept for the editor');
        $this->assertSame('<p>Hi</p>', $view->bodyHtml);
    }

    public function test_headings_get_ids_and_a_table_of_contents(): void
    {
        $view = $this->make(['body' => "## Background\n\ntext\n\n### Details\n\nmore\n\n## Background"]);

        $this->assertSame(
            [
                ['level' => 2, 'id' => 'background', 'text' => 'Background'],
                ['level' => 3, 'id' => 'details', 'text' => 'Details'],
                ['level' => 2, 'id' => 'background-2', 'text' => 'Background'],
            ],
            $view->toc,
        );
        $this->assertStringContainsString('<h2 id="background-2">', (string) $view->bodyHtml);
    }

    // ── tags ─────────────────────────────────────────────────────────────

    public function test_tags_accept_a_comma_separated_string_and_are_deduplicated(): void
    {
        $this->assertSame(['news', 'mobile-money'], $this->make(['tags' => 'News, news ,  Mobile Money,,'])->tags);
    }

    public function test_more_than_ten_tags_is_rejected(): void
    {
        try {
            $this->make(['tags' => array_map(static fn(int $i): string => "tag{$i}", range(1, 11))]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('tags', $e->errors);
        }
    }

    // ── slugs ────────────────────────────────────────────────────────────

    public function test_a_derived_slug_is_suffixed_to_stay_unique(): void
    {
        $slugs = [$this->make()->slug, $this->make()->slug, $this->make()->slug];

        $this->assertSame(['hello-world', 'hello-world-2', 'hello-world-3'], $slugs);
    }

    public function test_slugs_are_unique_per_kind_and_locale(): void
    {
        $this->assertSame('hello-world', $this->make(['kind' => 'blog'])->slug);
        $this->assertSame('hello-world', $this->make(['kind' => 'press'])->slug);
        $fr = $this->make(['kind' => 'blog', 'locale' => 'fr']);
        $this->assertSame('hello-world', $fr->slug);
        $this->assertSame('/fr/blog/hello-world', $fr->path);
    }

    public function test_an_explicit_slug_clash_is_a_field_error_not_a_silent_suffix(): void
    {
        $this->make(['slug' => 'launch']);

        try {
            $this->make(['slug' => 'launch']);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('slug', $e->errors);
        }
    }

    public function test_a_trashed_post_still_holds_its_slug(): void
    {
        $post = $this->make(['slug' => 'launch']);
        $this->posts->trash($post->id, self::ACTOR);

        $this->assertSame('launch-2', $this->posts->suggestSlug('Launch'), 'restoring must not find its address taken');

        try {
            $this->make(['slug' => 'launch']);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('slug', $e->errors);
        }
    }

    public function test_locale_is_normalised_to_bcp47_casing(): void
    {
        $this->assertSame('pt-BR', $this->make(['locale' => 'PT_br'])->locale);
        $this->assertSame('zh-Hant-TW', $this->make(['locale' => 'zh-hant-tw', 'title' => 'Other'])->locale);
    }

    // ── update ───────────────────────────────────────────────────────────

    public function test_update_replaces_fields_but_keeps_slug_and_date_when_omitted(): void
    {
        $created = $this->make();
        $this->clock->advance('+1 year');

        $updated = $this->posts->update($created->id, ['title' => 'A completely new title', 'published' => true], 'user-2');

        $this->assertNotNull($updated);
        $this->assertSame('A completely new title', $updated->title);
        $this->assertSame('hello-world', $updated->slug, 'the URL must not move');
        $this->assertSame($created->publishedAt, $updated->publishedAt, 'an edit must not re-date the post');
        $this->assertNull($updated->subtitle, 'full replace: an omitted field is cleared');
        $this->assertSame([], $updated->tags);
        $this->assertSame([], $this->posts->find($created->id)?->tags, 'old tags are gone from storage too');
    }

    public function test_resaving_a_post_with_its_own_slug_is_not_a_clash(): void
    {
        $created = $this->make(['slug' => 'launch']);

        $this->assertSame('launch', $this->posts->update($created->id, $this->input(['slug' => 'launch']), self::ACTOR)?->slug);
    }

    public function test_moving_a_post_into_a_kind_where_its_slug_is_taken_suffixes_it(): void
    {
        $this->make(['kind' => 'press']);
        $blog = $this->make(['kind' => 'blog']);

        $moved = $this->posts->update($blog->id, $this->input(['kind' => 'press']), self::ACTOR);

        $this->assertSame('press', $moved?->kind);
        $this->assertSame('hello-world-2', $moved?->slug);
    }

    public function test_update_of_a_missing_post_returns_null(): void
    {
        $this->assertNull($this->posts->update('01J00000000000000000000000', $this->input(), self::ACTOR));
    }

    public function test_update_validates_before_touching_the_database(): void
    {
        $before = count($this->db->statements);

        try {
            $this->posts->update('01J00000000000000000000000', ['title' => ''], self::ACTOR);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException) {
        }

        $this->assertSame($before, count($this->db->statements));
    }

    public function test_ids_and_slugs_are_matched_the_same_way_on_every_driver(): void
    {
        $created = $this->make();

        $this->assertNotNull($this->posts->find(' ' . strtolower($created->id) . ' '));
        $this->assertNotNull($this->posts->findPublished('BLOG', ' Hello-World ', $this->now()));
        $this->assertTrue($this->posts->purge(strtolower($created->id)));
    }

    // ── atomicity ────────────────────────────────────────────────────────

    public function test_a_failed_tag_write_rolls_back_the_post(): void
    {
        $this->db->failOn = 'INSERT INTO post_tags';

        try {
            $this->make();
            $this->fail('Expected a ServiceException.');
        } catch (ServiceException $e) {
            $this->assertSame('post.create.failed', $e->getMessage());
        }

        $this->assertFalse($this->db->inTransaction());
        $this->assertSame(0, $this->posts->list()->total, 'the post row was rolled back with its tags');
        $this->assertSame([], $this->db->query('SELECT * FROM post_revisions'));
        $this->assertSame([], $this->events->events, 'no event for a write that did not happen');
    }

    // ── admin list ───────────────────────────────────────────────────────

    public function test_list_filters_by_kind_status_tag_search_locale_and_author(): void
    {
        $this->make(['title' => 'Blog live', 'tags' => ['alpha'], 'authorId' => 'jane']);
        $this->make(['title' => 'Blog draft', 'published' => false]);
        $this->make(['title' => 'Press later', 'kind' => 'press', 'publishedAt' => '2026-12-01T00:00:00Z']);
        $this->make(['title' => 'En français', 'locale' => 'fr']);

        $this->assertSame(4, $this->posts->list()->total);
        $this->assertSame(['En français', 'Blog draft', 'Blog live'], self::titles($this->posts->list(kind: 'blog')->items));
        $this->assertSame(['Blog draft'], self::titles($this->posts->list(status: 'draft')->items));
        $this->assertSame(['Press later'], self::titles($this->posts->list(status: 'scheduled')->items));
        $this->assertSame(['Blog live'], self::titles($this->posts->list(tag: 'Alpha')->items));
        $this->assertSame(['Press later'], self::titles($this->posts->list(search: 'PRESS')->items));
        $this->assertSame(['En français'], self::titles($this->posts->list(locale: 'fr')->items));
        $this->assertSame(3, $this->posts->list(locale: '')->total, "'' is the default locale, not 'any'");
        $this->assertSame(['Blog live'], self::titles($this->posts->list(authorId: 'jane')->items));
    }

    public function test_search_matches_percent_and_underscore_literally_and_finds_tags(): void
    {
        $this->make(['title' => 'Rates up 20%', 'tags' => []]);
        $this->make(['title' => 'Rates up 200', 'tags' => ['elections']]);

        $this->assertSame(['Rates up 20%'], self::titles($this->posts->list(search: '20%')->items));
        $this->assertSame(0, $this->posts->list(search: '_')->total);
        $this->assertSame(['Rates up 200'], self::titles($this->posts->list(search: 'elections')->items));
    }

    public function test_a_tag_filter_that_normalises_to_nothing_matches_nothing(): void
    {
        $this->make();

        $this->assertSame(0, $this->posts->list(tag: '!!!')->total);
        $this->assertSame(['draft' => 0, 'scheduled' => 0, 'published' => 0, 'trash' => 0], $this->posts->statusCounts(tag: '!!!'));
    }

    public function test_an_author_archive_lists_the_byline_not_whoever_typed_it(): void
    {
        $this->make(['title' => 'By Jane', 'authorId' => 'jane']);
        $this->make(['title' => 'Jane draft', 'authorId' => 'jane', 'published' => false]);
        // Created by user-1 (the test actor) but credited to Jane.
        $this->make(['title' => 'Ghostwritten', 'authorId' => 'jane']);
        $this->make(['title' => 'By Sam', 'authorId' => 'sam']);

        $this->assertSame(['Ghostwritten', 'By Jane'], self::titles($this->posts->published($this->now(), author: 'jane')->items));
        // The admin "my posts" filter does match the creator; the public one must not.
        $this->assertSame(0, $this->posts->published($this->now(), author: self::ACTOR)->total);
    }

    public function test_each_status_count_equals_the_length_of_that_status_list(): void
    {
        $this->make(['title' => 'Blog live', 'tags' => ['alpha'], 'authorId' => 'jane']);
        $this->make(['title' => 'Blog draft', 'published' => false, 'tags' => ['alpha']]);
        $this->make(['title' => 'Press later', 'kind' => 'press', 'publishedAt' => '2026-12-01T00:00:00Z']);
        $this->make(['title' => 'En français', 'locale' => 'fr']);
        $gone = $this->make(['title' => 'Binned']);
        $this->posts->trash($gone->id, self::ACTOR);

        $filters = [[], ['kind' => 'blog'], ['kind' => 'press'], ['tag' => 'alpha'], ['search' => 'blog'], ['locale' => 'fr'], ['authorId' => 'jane']];
        foreach ($filters as $filter) {
            $counts = $this->posts->statusCounts(...$filter);
            foreach (['draft', 'scheduled', 'published', 'trash'] as $status) {
                $this->assertSame($this->posts->list(...$filter, status: $status)->total, $counts[$status], json_encode($filter) . " {$status}");
            }
        }

        $this->assertSame(['draft' => 1, 'scheduled' => 1, 'published' => 2, 'trash' => 1], $this->posts->statusCounts());
    }

    public function test_an_unknown_filter_is_a_validation_error(): void
    {
        try {
            $this->posts->list(kind: 'podcast', status: 'gone', locale: '!!', category: 'nope', order: 'random');
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            foreach (['kind', 'status', 'locale', 'category', 'order'] as $key) {
                $this->assertArrayHasKey($key, $e->errors);
            }
        }
    }

    public function test_pagination(): void
    {
        foreach (range(1, 5) as $i) {
            $this->make(['title' => "Post {$i}"]);
        }

        $page2 = $this->posts->list(page: 2, perPage: 2);

        $this->assertSame(['Post 3', 'Post 2'], self::titles($page2->items));
        $this->assertSame(5, $page2->total);
        $this->assertSame(3, $page2->lastPage());
        $this->assertTrue($page2->hasMore());
        $this->assertSame([], $this->posts->list(page: 9, perPage: 2)->items);
        $this->assertSame(1, $this->posts->list(page: -4)->page);
    }

    public function test_stats_count_every_kind_and_status(): void
    {
        $this->make();
        $this->make(['published' => false]);
        $trashed = $this->make(['kind' => 'press']);
        $this->posts->trash($trashed->id, self::ACTOR);

        $stats = $this->posts->stats();

        $this->assertSame(['draft' => 1, 'scheduled' => 0, 'published' => 1, 'trash' => 0], $stats['blog']);
        $this->assertSame(1, $stats['press']['trash']);
        $this->assertSame(0, $stats['press']['published']);
    }

    public function test_page_to_array_omits_bodies(): void
    {
        $this->make();

        $page = $this->posts->published($this->now())->toPublicArray();

        $this->assertArrayNotHasKey('body', $page['items'][0]);
        $this->assertArrayNotHasKey('bodyHtml', $page['items'][0]);
        $this->assertArrayNotHasKey('createdBy', $page['items'][0], 'public arrays hide bookkeeping');
        $this->assertSame(1, $page['total']);
    }

    public function test_kinds(): void
    {
        $this->assertSame(['blog', 'article', 'press'], $this->posts->kinds());
    }
}
