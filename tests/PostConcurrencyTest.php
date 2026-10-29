<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Posts;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\OptimisticLockException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use Plugins\Posts\API\Exceptions\PostEditConflictException;
use Plugins\Posts\Infrastructure\Persistence\PostRepository;
use Tests\Unit\Plugins\Posts\Support\PostsTestCase;

/**
 * Two people editing the same post, and translation groups staying one post
 * per language. Both were silent data loss before: the second save simply
 * overwrote the first, and a locale change could give a group two versions in
 * the same language.
 */
final class PostConcurrencyTest extends PostsTestCase
{
    public function test_a_save_against_a_stale_version_is_a_conflict_not_an_overwrite(): void
    {
        $post = $this->make(['title' => 'Original']);
        $this->assertSame(1, $post->version);

        // Alice and Bob both open version 1. Alice saves first.
        $alice = $this->posts->update($post->id, $this->input(['title' => 'Alice', 'version' => 1]), self::ACTOR);
        $this->assertSame(2, $alice?->version);

        try {
            $this->posts->update($post->id, $this->input(['title' => 'Bob', 'version' => 1]), self::ACTOR);
            $this->fail('Bob overwrote Alice.');
        } catch (PostEditConflictException $e) {
            $this->assertSame(409, $e->httpStatus());
            $this->assertSame(2, $e->currentVersion);
        }

        $this->assertSame('Alice', $this->posts->find($post->id)?->title);
        $this->assertCount(2, $this->posts->revisions($post->id), 'the refused save left no revision');
    }

    public function test_resending_against_the_current_version_succeeds(): void
    {
        $post = $this->make();
        $this->posts->update($post->id, $this->input(['title' => 'One', 'version' => 1]), self::ACTOR);

        $this->assertSame('Two', $this->posts->update($post->id, $this->input(['title' => 'Two', 'version' => 2]), self::ACTOR)?->title);
        $this->assertSame(3, $this->posts->find($post->id)?->version);
    }

    public function test_without_a_version_a_save_still_succeeds(): void
    {
        $post = $this->make();

        $this->assertSame('Free', $this->posts->update($post->id, $this->input(['title' => 'Free']), self::ACTOR)?->title);
    }

    public function test_the_write_itself_is_conditional_on_the_version_it_read(): void
    {
        // Two requests interleaved between read and write: both loaded v1.
        $post  = $this->make();
        $repo  = new PostRepository($this->db);
        $first = $repo->findByPublicId($post->id);
        $late  = $repo->findByPublicId($post->id);

        $this->assertTrue($repo->update($first));
        $this->assertSame(2, $first->version());

        $this->expectException(OptimisticLockException::class);
        $repo->update($late);
    }

    public function test_the_public_view_does_not_carry_the_version(): void
    {
        $this->assertArrayNotHasKey('version', $this->make()->toPublicArray());
    }

    public function test_a_translation_cannot_move_to_a_language_its_group_already_has(): void
    {
        $en = $this->make(['title' => 'Hello']);
        $this->posts->create($this->input(['title' => 'Hola', 'locale' => 'es', 'translationOf' => $en->id]), self::ACTOR);
        $fr = $this->posts->create($this->input(['title' => 'Bonjour', 'locale' => 'fr', 'translationOf' => $en->id]), self::ACTOR);

        try {
            $this->posts->update($fr->id, $this->input(['title' => 'Bonjour', 'locale' => 'es']), self::ACTOR);
            $this->fail('The group now has two Spanish versions.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('locale', $e->errors);
        }

        // A language the group lacks is fine.
        $this->assertSame('de', $this->posts->update($fr->id, $this->input(['title' => 'Hallo', 'locale' => 'de']), self::ACTOR)?->locale);
    }
}
