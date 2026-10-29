<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Posts;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\Attributes\CoversClass;
use Plugins\Posts\Application\Services\PostEditorialService;
use Tests\Unit\Plugins\Posts\Support\PostsTestCase;

/**
 * The admin API's permission model: write (contributor), publish (author),
 * manage (editor).
 */
#[CoversClass(PostEditorialService::class)]
final class PostEditorialServiceTest extends PostsTestCase
{
    private function as(string $userId, array $permissions, string $fullName = ''): PostEditorialService
    {
        return new PostEditorialService(
            $this->posts,
            $this->categories,
            new Identity($userId, 'tenant-1', [], $permissions, 'session', fullName: $fullName),
        );
    }

    private function contributor(): PostEditorialService
    {
        return $this->as('alice', ['posts:write'], 'Alice Writer');
    }

    private function author(): PostEditorialService
    {
        return $this->as('bob', ['posts:write', 'posts:publish']);
    }

    private function editor(): PostEditorialService
    {
        return $this->as('erin', ['posts:manage']);
    }

    private function assertDenied(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected a 403 SecurityException.');
        } catch (SecurityException $e) {
            $this->assertSame(403, $e->getCode());
        }
    }

    public function test_is_editor_needs_one_of_the_three_permissions(): void
    {
        $this->assertTrue(PostEditorialService::isEditor(new Identity('u', 't', [], ['posts:write'], 'session'), 'posts:write', 'posts:publish', 'posts:manage'));
        $this->assertTrue(PostEditorialService::isEditor(new Identity('u', 't', [], ['*'], 'session'), 'posts:write', 'posts:publish', 'posts:manage'));
        $this->assertFalse(PostEditorialService::isEditor(new Identity('u', 't', [], ['other'], 'session'), 'posts:write', 'posts:publish', 'posts:manage'));
        $this->assertFalse(PostEditorialService::isEditor(Identity::guest(), 'posts:write', 'posts:publish', 'posts:manage'));
        $this->assertFalse(PostEditorialService::isEditor(null, 'posts:write', 'posts:publish', 'posts:manage'));
    }

    public function test_a_contributor_writes_drafts_bylined_to_themselves(): void
    {
        $draft = $this->contributor()->create(['title' => 'My draft']);

        $this->assertSame('alice', $draft->authorId);
        $this->assertSame('Alice Writer', $draft->authorName);
        $this->assertSame('draft', $draft->status);
    }

    public function test_a_contributor_cannot_publish_or_byline_someone_else(): void
    {
        $this->assertDenied(fn() => $this->contributor()->create(['title' => 'X', 'published' => true]));
        $this->assertDenied(fn() => $this->contributor()->create(['title' => 'X', 'authorId' => 'bob']));
    }

    public function test_a_contributor_cannot_edit_a_published_post_even_their_own(): void
    {
        $draft = $this->contributor()->create(['title' => 'Mine']);
        $this->posts->update($draft->id, ['title' => 'Mine', 'published' => true, 'authorId' => 'alice'], 'erin');

        $this->assertDenied(fn() => $this->contributor()->update($draft->id, ['title' => 'Sneaky edit']));
        $this->assertDenied(fn() => $this->contributor()->trash($draft->id));
    }

    public function test_nobody_but_a_manager_touches_other_peoples_posts(): void
    {
        $bobs = $this->author()->create(['title' => "Bob's"]);

        $this->assertDenied(fn() => $this->contributor()->find($bobs->id));
        $this->assertDenied(fn() => $this->contributor()->update($bobs->id, ['title' => 'Hijack']));
        $this->assertSame('Edited by editor', $this->editor()->update($bobs->id, ['title' => 'Edited by editor'])?->title);
        $this->assertSame('bob', $this->posts->find($bobs->id)?->authorId, 'an editor editing keeps the byline');
    }

    public function test_an_author_publishes_their_own(): void
    {
        $post = $this->author()->create(['title' => 'Out now', 'published' => true]);

        $this->assertSame('published', $post->status);
        $this->assertTrue($this->author()->trash($post->id));
    }

    public function test_non_managers_list_only_their_own_posts(): void
    {
        $this->contributor()->create(['title' => 'Alice 1']);
        $this->author()->create(['title' => 'Bob 1']);

        $this->assertSame(['Alice 1'], self::titles($this->contributor()->list(['author' => 'bob'])->items));
        $this->assertSame(2, $this->editor()->list([])->total);
        $this->assertSame(['Bob 1'], self::titles($this->editor()->list(['author' => 'bob'])->items));

        // The status tabs are scoped the same way as the list.
        $this->assertSame(1, array_sum($this->contributor()->statusCounts(['author' => 'bob'])));
        $this->assertSame(2, array_sum($this->editor()->statusCounts([])));
    }

    public function test_purge_categories_and_stats_need_manage(): void
    {
        $post = $this->author()->create(['title' => 'X']);

        $this->assertDenied(fn() => $this->author()->purge($post->id));
        $this->assertDenied(fn() => $this->author()->createCategory(['name' => 'News']));
        $this->assertDenied(fn() => $this->author()->stats());

        $this->assertSame('News', $this->editor()->createCategory(['name' => 'News'])->name);
        $this->assertTrue($this->editor()->purge($post->id));
    }

    public function test_bulk_applies_per_post_and_reports_each_outcome(): void
    {
        $mine   = $this->author()->create(['title' => 'Mine']);
        $theirs = $this->editor()->create(['title' => 'Theirs']);

        $result = $this->author()->bulk('publish', [$mine->id, $theirs->id, '01J00000000000000000000000']);

        $this->assertSame([$mine->id => 'ok', $theirs->id => 'forbidden', '01J00000000000000000000000' => 'not_found'], $result);
        $this->assertSame('published', $this->posts->find($mine->id)?->status);
        $this->assertSame('draft', $this->posts->find($theirs->id)?->status);
    }

    public function test_capabilities_describe_what_the_ui_may_offer(): void
    {
        $kinds = ['kinds' => ['blog', 'article', 'press']];
        $this->assertSame($kinds + ['userId' => 'alice', 'canWrite' => true, 'canPublish' => false, 'canManage' => false], $this->contributor()->capabilities());
        $this->assertSame($kinds + ['userId' => 'erin', 'canWrite' => true, 'canPublish' => true, 'canManage' => true], $this->editor()->capabilities());
    }
}
