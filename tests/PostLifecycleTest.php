<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Posts;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use Plugins\Posts\Application\Services\PostService;
use Plugins\Posts\Application\Support\PreviewTokens;
use Tests\Unit\Plugins\Posts\Support\PostsTestCase;

/**
 * Events, scheduling, trash, revisions, redirects, duplicates and previews —
 * the behaviour that happens AROUND an edit.
 */
#[CoversClass(PostService::class)]
#[CoversClass(PreviewTokens::class)]
final class PostLifecycleTest extends PostsTestCase
{
    // ── events ───────────────────────────────────────────────────────────

    public function test_publishing_announces_once_and_edits_announce_updates(): void
    {
        $post = $this->make();
        $this->assertSame(['post.published'], $this->events->names());
        $this->assertSame('/blog/hello-world', $this->events->events[0]->path);
        $this->assertSame($post->id, $this->events->events[0]->payload()['postId']);

        $this->posts->update($post->id, $this->input(['title' => 'Edited']), self::ACTOR);
        $this->assertSame(['post.published', 'post.updated'], $this->events->names());
    }

    public function test_a_draft_announces_nothing_until_it_is_published(): void
    {
        $draft = $this->make(['published' => false]);
        $this->posts->update($draft->id, $this->input(['published' => false, 'title' => 'Still a draft']), self::ACTOR);
        $this->assertSame([], $this->events->names());

        $this->posts->update($draft->id, $this->input(['published' => true]), self::ACTOR);
        $this->assertSame(['post.published'], $this->events->names());
    }

    public function test_unpublishing_announces_and_republishing_announces_again(): void
    {
        $post = $this->make();
        $this->posts->update($post->id, $this->input(['published' => false]), self::ACTOR);
        $this->posts->update($post->id, $this->input(['published' => true]), self::ACTOR);

        $this->assertSame(['post.published', 'post.unpublished', 'post.published'], $this->events->names());
    }

    public function test_the_payload_carries_no_body(): void
    {
        $this->make();

        $this->assertSame(
            ['postId', 'kind', 'locale', 'slug', 'path', 'publishedAt', 'occurredAt', 'version'],
            array_keys($this->events->events[0]->payload()),
        );
    }

    // ── scheduling ───────────────────────────────────────────────────────

    public function test_a_scheduled_post_is_announced_by_publish_due_exactly_once(): void
    {
        $post = $this->make(['publishedAt' => '2026-10-01T12:00:00Z']);
        $this->assertSame('scheduled', $post->status);
        $this->assertSame([], $this->events->names(), 'not visible yet — nothing to announce');

        $this->assertSame(0, $this->posts->publishDue($this->now()), 'not due yet');

        $this->clock->set('2026-10-01T12:00:00Z');
        $this->assertSame(1, $this->posts->publishDue($this->now()));
        $this->assertSame(0, $this->posts->publishDue($this->now()), 'a second run sends nothing');
        $this->assertSame(['post.published'], $this->events->names());
        $this->assertSame('published', $this->posts->find($post->id)?->status);
    }

    public function test_publish_due_skips_posts_announced_on_save(): void
    {
        $this->make();
        $this->events->clear();

        $this->assertSame(0, $this->posts->publishDue($this->now()));
    }

    public function test_rescheduling_a_live_post_unpublishes_it_and_rearms_the_announcement(): void
    {
        $post = $this->make();
        $this->posts->update($post->id, $this->input(['publishedAt' => '2026-12-01T00:00:00Z']), self::ACTOR);
        $this->assertSame(['post.published', 'post.unpublished'], $this->events->names());

        $this->clock->set('2026-12-01T00:00:00Z');
        $this->assertSame(1, $this->posts->publishDue($this->now()));
    }

    // ── trash ────────────────────────────────────────────────────────────

    public function test_trash_hides_restore_returns_and_purge_removes_everything(): void
    {
        $post = $this->make(['slug' => 'launch']);

        $this->assertTrue($this->posts->trash($post->id, self::ACTOR));
        $this->assertFalse($this->posts->trash($post->id, self::ACTOR), 'already in the trash');
        $this->assertNull($this->posts->findPublished('blog', 'launch', $this->now()));
        $this->assertSame(0, $this->posts->list()->total, 'the default list hides the trash');
        $this->assertSame(1, $this->posts->list(status: 'trash')->total);
        $this->assertSame('trash', $this->posts->find($post->id)?->status);

        $restored = $this->posts->restore($post->id, self::ACTOR);
        $this->assertSame('published', $restored?->status);
        $this->assertSame('launch', $restored?->slug);
        $this->assertNull($this->posts->restore($post->id, self::ACTOR), 'not in the trash');

        $this->assertTrue($this->posts->purge($post->id));
        $this->assertNull($this->posts->find($post->id));
        foreach (['post_tags', 'post_revisions', 'post_category_links', 'post_slug_redirects'] as $table) {
            $this->assertSame([], $this->db->query("SELECT * FROM {$table}"), "{$table} not purged");
        }

        $this->assertSame(
            ['post.published', 'post.unpublished', 'post.published', 'post.deleted'],
            $this->events->names(),
        );
    }

    public function test_empty_trash_purges_only_what_is_old_enough(): void
    {
        $old = $this->make(['title' => 'Old']);
        $this->posts->trash($old->id, self::ACTOR);
        $this->clock->advance('+31 days');
        $recent = $this->make(['title' => 'Recent']);
        $this->posts->trash($recent->id, self::ACTOR);

        $this->assertSame(1, $this->posts->emptyTrash($this->now()->modify('-30 days')));
        $this->assertNull($this->posts->find($old->id));
        $this->assertNotNull($this->posts->find($recent->id));
    }

    // ── revisions ────────────────────────────────────────────────────────

    public function test_every_save_is_a_revision_and_any_can_be_restored(): void
    {
        $post = $this->make(['title' => 'First title']);
        $this->posts->update($post->id, $this->input(['title' => 'Second title']), self::ACTOR, 'Copy edit');

        $history = $this->posts->revisions($post->id);
        $this->assertSame([2, 1], array_column(array_map(static fn($r) => $r->toArray(), $history), 'number'));
        $this->assertSame('Copy edit', $history[0]->note);
        $this->assertSame('Created', $history[1]->note);
        $this->assertNull($history[0]->snapshot, 'lists carry no snapshots');

        $restored = $this->posts->restoreRevision($post->id, 1, 'user-2');
        $this->assertSame('First title', $restored?->title);
        $this->assertSame('Restored revision #1', $this->posts->revisions($post->id)[0]->note);
        $this->assertSame('First title', $this->posts->revision($post->id, 3)?->snapshot['title']);
    }

    public function test_revisions_are_pruned_to_the_configured_count_but_numbers_never_repeat(): void
    {
        $this->env['POSTS_MAX_REVISIONS'] = '3';
        $this->build();

        $post = $this->make();
        foreach (range(1, 5) as $i) {
            $this->posts->update($post->id, $this->input(['title' => "Edit {$i}"]), self::ACTOR);
        }

        $numbers = array_map(static fn($r): int => $r->number, $this->posts->revisions($post->id));
        $this->assertSame([6, 5, 4], $numbers);
    }

    // ── redirects ────────────────────────────────────────────────────────

    public function test_renaming_a_public_post_leaves_a_redirect_behind(): void
    {
        $post = $this->make(['slug' => 'old-name']);
        $this->posts->update($post->id, $this->input(['slug' => 'new-name']), self::ACTOR);

        $this->assertNull($this->posts->findPublished('blog', 'old-name', $this->now()));
        $this->assertSame('/blog/new-name', $this->posts->redirectFor('blog', 'old-name', $this->now())?->path);
        $this->assertNull($this->posts->redirectFor('blog', 'never-existed', $this->now()));
    }

    public function test_a_new_post_taking_an_old_address_ends_the_redirect(): void
    {
        $post = $this->make(['slug' => 'old-name']);
        $this->posts->update($post->id, $this->input(['slug' => 'new-name']), self::ACTOR);

        $this->make(['slug' => 'old-name', 'title' => 'Squatter']);

        $this->assertSame('Squatter', $this->posts->findPublished('blog', 'old-name', $this->now())?->title);
        $this->assertSame([], $this->db->query("SELECT * FROM post_slug_redirects WHERE slug = 'old-name'"));
    }

    public function test_renaming_a_draft_leaves_no_redirect(): void
    {
        $draft = $this->make(['slug' => 'draft-name', 'published' => false]);
        $this->posts->update($draft->id, $this->input(['slug' => 'renamed', 'published' => false]), self::ACTOR);

        $this->assertSame([], $this->db->query('SELECT * FROM post_slug_redirects'), 'no reader ever saw the old address');
    }

    public function test_a_redirect_to_a_post_that_is_no_longer_public_is_a_404(): void
    {
        $post = $this->make(['slug' => 'old-name']);
        $this->posts->update($post->id, $this->input(['slug' => 'new-name']), self::ACTOR);
        $this->posts->trash($post->id, self::ACTOR);

        $this->assertNull($this->posts->redirectFor('blog', 'old-name', $this->now()));
    }

    // ── duplicate ────────────────────────────────────────────────────────

    public function test_duplicate_makes_an_unpublished_copy_with_its_own_slug(): void
    {
        $source = $this->make(['featured' => true]);

        $copy = $this->posts->duplicate($source->id, 'user-2');

        $this->assertSame('Hello World (copy)', $copy?->title);
        $this->assertSame('hello-world-copy', $copy?->slug);
        $this->assertSame('draft', $copy?->status);
        $this->assertFalse($copy?->featured);
        $this->assertSame($source->tags, $copy?->tags);
        $this->assertSame($source->body, $copy?->body);
        $this->assertNull($this->posts->duplicate('01J00000000000000000000000', self::ACTOR));
    }

    // ── preview links ────────────────────────────────────────────────────

    public function test_a_preview_token_opens_a_draft_until_it_expires(): void
    {
        $draft = $this->make(['published' => false]);
        $token = $this->posts->previewToken($draft->id, 3600);

        $this->assertSame($draft->id, $this->posts->findForPreview($token, $this->now())?->id);
        $this->assertNull($this->posts->findForPreview($token, $this->now()->modify('+2 hours')), 'expired');
        $this->assertNull($this->posts->findForPreview($token . 'x', $this->now()), 'tampered');
        $this->assertNull($this->posts->findForPreview('garbage', $this->now()));

        $this->posts->trash($draft->id, self::ACTOR);
        $this->assertNull($this->posts->findForPreview($token, $this->now()), 'trashed posts never preview');
    }

    public function test_a_token_signed_with_another_secret_is_refused(): void
    {
        $draft = $this->make(['published' => false]);
        $token = $this->posts->previewToken($draft->id);

        $this->env['APP_KEY'] = 'rotated-key';
        $this->build();

        $this->assertNull($this->posts->findForPreview($token, $this->now()));
    }

    public function test_previews_fail_closed_without_a_secret(): void
    {
        $this->env = [];
        $this->build();
        $draft = $this->make(['published' => false]);

        $this->expectException(ServiceException::class);
        $this->posts->previewToken($draft->id);
    }

    public function test_a_preview_token_for_an_unknown_post_is_a_validation_error(): void
    {
        $this->expectException(ValidationException::class);
        $this->posts->previewToken('01J00000000000000000000000');
    }

    // ── re-render ────────────────────────────────────────────────────────

    public function test_rerender_walks_every_post_in_batches_without_touching_timestamps(): void
    {
        foreach (range(1, 3) as $i) {
            $this->make(['title' => "Post {$i}"]);
        }
        $this->db->execute("UPDATE posts SET body_html = 'stale'");
        $before = $this->db->query('SELECT updated_at FROM posts ORDER BY id');

        $first = $this->posts->rerender(2);
        $this->assertSame(2, $first['processed']);
        $this->assertNotNull($first['last']);

        $second = $this->posts->rerender(2, $first['last']);
        $this->assertSame(['processed' => 1, 'last' => null], $second);

        $this->assertSame([], $this->db->query("SELECT id FROM posts WHERE body_html = 'stale'"));
        $this->assertSame($before, $this->db->query('SELECT updated_at FROM posts ORDER BY id'));
    }
}
