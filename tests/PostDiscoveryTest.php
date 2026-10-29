<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Posts;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use Plugins\Posts\Application\Services\PostCategoryService;
use Plugins\Posts\Application\Services\PostService;
use Tests\Unit\Plugins\Posts\Support\PostsTestCase;

/**
 * What a READER sees: public listings, single posts with their neighbours and
 * related reading, categories, translations, tags, archives and popularity.
 */
#[CoversClass(PostService::class)]
#[CoversClass(PostCategoryService::class)]
final class PostDiscoveryTest extends PostsTestCase
{
    // ── public listings ──────────────────────────────────────────────────

    public function test_published_shows_only_what_readers_may_see_newest_first(): void
    {
        $this->make(['title' => 'Old', 'publishedAt' => '2026-01-01T00:00:00Z']);
        $this->make(['title' => 'New', 'publishedAt' => '2026-09-01T00:00:00Z']);
        $this->make(['title' => 'Draft', 'published' => false]);
        $this->make(['title' => 'Future', 'publishedAt' => '2026-12-01T00:00:00Z']);
        $trashed = $this->make(['title' => 'Trashed']);
        $this->posts->trash($trashed->id, self::ACTOR);

        $this->assertSame(['New', 'Old'], self::titles($this->posts->published($this->now())->items));

        $later = new \DateTimeImmutable('2026-12-02T00:00:00Z');
        $this->assertSame(['Future', 'New', 'Old'], self::titles($this->posts->published($later)->items));
    }

    public function test_published_filters_by_kind_featured_and_month(): void
    {
        $this->make(['title' => 'Blog', 'publishedAt' => '2026-08-15T00:00:00Z']);
        $this->make(['title' => 'Article', 'kind' => 'article', 'featured' => true, 'publishedAt' => '2026-09-02T00:00:00Z']);
        $this->make(['title' => 'Press', 'kind' => 'press', 'featured' => 'on', 'publishedAt' => '2026-09-20T00:00:00Z']);

        $this->assertSame(['Press'], self::titles($this->posts->published($this->now(), kind: 'press')->items));
        $this->assertSame(['Press', 'Article'], self::titles($this->posts->published($this->now(), featuredOnly: true)->items));
        $this->assertSame(['Press', 'Article'], self::titles($this->posts->published($this->now(), year: 2026, month: 9)->items));
        $this->assertSame(['Blog'], self::titles($this->posts->published($this->now(), year: 2026, month: 8)->items));
        $this->assertSame(3, $this->posts->published($this->now(), year: 2026)->total);
    }

    public function test_a_bad_month_is_a_validation_error(): void
    {
        try {
            $this->posts->published($this->now(), month: 13);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('month', $e->errors);
        }
    }

    public function test_find_published_hides_drafts_scheduled_trashed_and_unknown_kinds(): void
    {
        $this->make(['slug' => 'out']);
        $this->make(['slug' => 'draft', 'published' => false]);
        $this->make(['slug' => 'soon', 'publishedAt' => '2026-12-01T00:00:00Z']);

        $this->assertNotNull($this->posts->findPublished('blog', 'out', $this->now()));
        $this->assertNull($this->posts->findPublished('press', 'out', $this->now()), 'wrong kind');
        $this->assertNull($this->posts->findPublished('podcast', 'out', $this->now()), 'unknown kind');
        $this->assertNull($this->posts->findPublished('blog', 'draft', $this->now()));
        $this->assertNull($this->posts->findPublished('blog', 'soon', $this->now()));
        $this->assertNull($this->posts->findPublished('blog', 'out', $this->now(), locale: '!!'), 'a malformed locale is a 404');
    }

    // ── single post page ─────────────────────────────────────────────────

    public function test_show_returns_neighbours_related_posts_and_translations(): void
    {
        $first  = $this->make(['title' => 'First', 'slug' => 'first', 'tags' => ['a'], 'publishedAt' => '2026-09-01T00:00:00Z']);
        $middle = $this->make(['title' => 'Middle', 'slug' => 'middle', 'tags' => ['a', 'b'], 'publishedAt' => '2026-09-02T00:00:00Z']);
        $this->make(['title' => 'Last', 'slug' => 'last', 'tags' => ['b'], 'publishedAt' => '2026-09-03T00:00:00Z']);
        $this->make(['title' => 'Unrelated', 'slug' => 'unrelated', 'tags' => ['z'], 'publishedAt' => '2026-08-01T00:00:00Z']);
        $this->make(['title' => 'Draft twin', 'tags' => ['a', 'b'], 'published' => false]);
        $this->posts->create($this->input(['title' => 'Milieu', 'slug' => 'milieu', 'locale' => 'fr', 'translationOf' => $middle->id]), self::ACTOR);

        $detail = $this->posts->show('blog', 'middle', $this->now());

        $this->assertNotNull($detail);
        $this->assertSame('Middle', $detail->post->title);
        $this->assertSame('First', $detail->previous?->title);
        $this->assertSame('Last', $detail->next?->title);
        $this->assertSame(['Last', 'First'], self::titles($detail->related), 'shared tags, never drafts, never itself');
        $this->assertSame(['fr'], array_map(static fn($t) => $t->locale, $detail->translations));

        $array = $detail->toPublicArray();
        $this->assertSame('/fr/blog/milieu', $array['translations'][0]['path']);
        $this->assertArrayHasKey('bodyHtml', $array['post']);
        $this->assertArrayNotHasKey('bodyHtml', $array['next']);

        $this->assertSame('Unrelated', $this->posts->show('blog', 'first', $this->now())?->previous?->title, 'neighbours follow date, not topic');
        $this->assertNull($this->posts->show('blog', 'unrelated', $this->now())?->previous, 'the oldest has no previous');
        $this->assertSame($first->id, $this->posts->show('blog', 'middle', $this->now())?->previous?->id);
    }

    public function test_neighbours_with_the_same_date_fall_back_to_creation_order(): void
    {
        $at = '2026-09-01T00:00:00Z';
        $this->make(['title' => 'A', 'slug' => 'a', 'publishedAt' => $at]);
        $this->make(['title' => 'B', 'slug' => 'b', 'publishedAt' => $at]);
        $this->make(['title' => 'C', 'slug' => 'c', 'publishedAt' => $at]);

        $b = $this->posts->show('blog', 'b', $this->now());

        $this->assertSame('A', $b?->previous?->title);
        $this->assertSame('C', $b?->next?->title);
    }

    // ── translations ─────────────────────────────────────────────────────

    public function test_translations_share_a_group_and_a_locale_appears_once(): void
    {
        $en = $this->make(['title' => 'Hello']);
        $fr = $this->posts->create($this->input(['title' => 'Bonjour', 'locale' => 'fr', 'translationOf' => $en->id]), self::ACTOR);
        $sw = $this->posts->create($this->input(['title' => 'Habari', 'locale' => 'sw', 'translationOf' => $fr->id]), self::ACTOR);

        $this->assertSame($en->id, $fr->translationGroup);
        $this->assertSame($en->id, $sw->translationGroup);
        $this->assertSame($en->id, $this->posts->find($en->id)?->translationGroup, 'the source joins the group too');
        $this->assertSame(['', 'fr', 'sw'], array_map(static fn($v) => $v->locale, $this->posts->translations($sw->id)));

        try {
            $this->posts->create($this->input(['title' => 'Salut', 'locale' => 'fr', 'translationOf' => $en->id]), self::ACTOR);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('locale', $e->errors);
        }
    }

    public function test_translation_of_an_unknown_post_is_a_validation_error(): void
    {
        $this->expectException(ValidationException::class);
        $this->posts->create($this->input(['locale' => 'fr', 'translationOf' => '01J00000000000000000000000']), self::ACTOR);
    }

    // ── categories ───────────────────────────────────────────────────────

    public function test_categories_form_a_tree_and_a_listing_includes_descendants(): void
    {
        $elections = $this->categories->create(['name' => 'Elections']);
        $y2026     = $this->categories->create(['name' => '2026', 'parentId' => $elections->id]);
        $sport     = $this->categories->create(['name' => 'Sport', 'position' => -1]);

        $this->make(['title' => 'Parent post', 'categories' => [$elections->id]]);
        $this->make(['title' => 'Child post', 'categories' => [$y2026->id]]);
        $this->make(['title' => 'Draft child', 'categories' => [$y2026->id], 'published' => false]);

        $tree = $this->categories->tree($this->now());
        $this->assertSame(['Sport', 'Elections', '2026'], array_map(static fn($c) => $c->name, $tree));
        $this->assertSame([0, 0, 1], array_map(static fn($c) => $c->depth, $tree));
        $this->assertSame(['Elections'], $tree[2]->ancestors);
        $this->assertSame(2, $tree[1]->postCount, 'public posts in the category and below');
        $this->assertSame(1, $tree[2]->postCount);

        $this->assertSame(['Child post', 'Parent post'], self::titles($this->posts->published($this->now(), category: 'elections')->items));
        $this->assertSame(['Child post'], self::titles($this->posts->published($this->now(), category: '2026')->items));

        $view = $this->posts->find($this->posts->published($this->now(), category: '2026')->items[0]->id);
        $this->assertSame([['id' => $y2026->id, 'slug' => '2026', 'name' => '2026']], $view?->categories);
    }

    public function test_a_category_cannot_move_under_itself(): void
    {
        $parent = $this->categories->create(['name' => 'Parent']);
        $child  = $this->categories->create(['name' => 'Child', 'parentId' => $parent->id]);

        try {
            $this->categories->update($parent->id, ['name' => 'Parent', 'parentId' => $child->id]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('parentId', $e->errors);
        }
    }

    public function test_deleting_a_category_reparents_children_and_unlinks_posts(): void
    {
        $root   = $this->categories->create(['name' => 'Root']);
        $middle = $this->categories->create(['name' => 'Middle', 'parentId' => $root->id]);
        $leaf   = $this->categories->create(['name' => 'Leaf', 'parentId' => $middle->id]);
        $post   = $this->make(['categories' => [$middle->id, $leaf->id]]);

        $this->assertTrue($this->categories->delete($middle->id));

        $this->assertSame($root->id, $this->categories->find($leaf->id)?->parentId);
        $this->assertSame([$leaf->id], array_column($this->posts->find($post->id)?->categories ?? [], 'id'));
        $this->assertFalse($this->categories->delete($middle->id));
    }

    public function test_category_slugs_are_unique_and_validated(): void
    {
        $this->categories->create(['name' => 'News']);
        $this->assertSame('news-2', $this->categories->create(['name' => 'News'])->slug, 'a derived slug is suffixed');

        try {
            $this->categories->create(['name' => '', 'slug' => 'news', 'position' => 'x']);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->errors);
            $this->assertArrayHasKey('slug', $e->errors, 'an explicit clash is an error');
            $this->assertArrayHasKey('position', $e->errors);
        }
    }

    public function test_restoring_a_revision_drops_categories_deleted_since(): void
    {
        $category = $this->categories->create(['name' => 'Temporary']);
        $post     = $this->make(['categories' => [$category->id]]);
        $this->categories->delete($category->id);

        $restored = $this->posts->restoreRevision($post->id, 1, self::ACTOR);

        $this->assertSame([], $restored?->categories);
    }

    // ── tags, archive, popularity ────────────────────────────────────────

    public function test_tag_counts_cover_public_posts_only(): void
    {
        $this->make(['tags' => ['news', 'tech']]);
        $this->make(['tags' => ['news']]);
        $this->make(['tags' => ['news', 'secret'], 'published' => false]);
        $this->make(['kind' => 'press', 'tags' => ['tech']]);

        $this->assertSame([['tag' => 'news', 'count' => 2], ['tag' => 'tech', 'count' => 2]], $this->posts->tags($this->now()));
        $this->assertSame([['tag' => 'news', 'count' => 2], ['tag' => 'tech', 'count' => 1]], $this->posts->tags($this->now(), kind: 'blog'));
    }

    public function test_archive_groups_public_posts_by_month_newest_first(): void
    {
        $this->make(['publishedAt' => '2026-09-03T00:00:00Z']);
        $this->make(['publishedAt' => '2026-09-01T00:00:00Z']);
        $this->make(['publishedAt' => '2025-12-31T23:59:59Z']);
        $this->make(['published' => false]);

        $this->assertSame(
            [['year' => 2026, 'month' => 9, 'count' => 2], ['year' => 2025, 'month' => 12, 'count' => 1]],
            $this->posts->archive($this->now()),
        );
    }

    public function test_views_count_only_on_public_posts_and_rank_popular(): void
    {
        $quiet   = $this->make(['title' => 'Quiet']);
        $popular = $this->make(['title' => 'Popular']);
        $draft   = $this->make(['title' => 'Draft', 'published' => false]);

        $this->assertTrue($this->posts->recordView($popular->id, $this->now()));
        $this->assertTrue($this->posts->recordView($popular->id, $this->now()));
        $this->assertTrue($this->posts->recordView($quiet->id, $this->now()));
        $this->assertFalse($this->posts->recordView($draft->id, $this->now()));

        $this->assertSame(['Popular', 'Quiet'], self::titles($this->posts->popular($this->now())));
        $this->assertSame(2, $this->posts->find($popular->id)?->viewCount);

        // A save never resets the counter.
        $this->posts->update($popular->id, $this->input(['title' => 'Popular']), self::ACTOR);
        $this->assertSame(2, $this->posts->find($popular->id)?->viewCount);
    }
}
