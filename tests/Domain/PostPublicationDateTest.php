<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Posts\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Posts\Domain\Entities\Post;
use Plugins\Posts\Domain\ValueObjects\BodyFormat;
use Plugins\Posts\Domain\ValueObjects\PostContent;
use Plugins\Posts\Domain\ValueObjects\PostKind;
use Plugins\Posts\Domain\ValueObjects\PostLocale;
use Plugins\Posts\Domain\ValueObjects\PostSlug;
use Plugins\Posts\Domain\ValueObjects\PostStatus;
use Plugins\Posts\Domain\ValueObjects\PostTitle;
use Plugins\Posts\Domain\ValueObjects\RenderedBody;

/**
 * The one rule the Post aggregate owns: what happens to `publishedAt` when an
 * editor saves without giving one.
 */
#[CoversClass(Post::class)]
final class PostPublicationDateTest extends TestCase
{
    private \DateTimeImmutable $t0;

    protected function setUp(): void
    {
        $this->t0 = new \DateTimeImmutable('2026-10-01 09:00:00', new \DateTimeZone('UTC'));
    }

    private function content(bool $published, ?\DateTimeImmutable $publishedAt = null): PostContent
    {
        return new PostContent(
            kind: PostKind::Press,
            locale: PostLocale::none(),
            slug: PostSlug::fromString('launch'),
            title: PostTitle::fromString('Launch'),
            subtitle: null,
            excerpt: null,
            body: null,
            bodyFormat: BodyFormat::Markdown,
            coverImageUrl: null,
            coverImageAlt: null,
            authorId: null,
            authorName: null,
            tags: [],
            categoryIds: [],
            dateline: null,
            contact: null,
            sourceName: null,
            sourceUrl: null,
            metaTitle: null,
            metaDescription: null,
            canonicalUrl: null,
            noindex: false,
            featured: false,
            commentsEnabled: true,
            published: $published,
            publishedAt: $publishedAt,
        );
    }

    public function test_a_draft_has_no_publication_date(): void
    {
        $post = Post::create($this->content(false), RenderedBody::empty(), null, 'u1', $this->t0);

        $this->assertNull($post->content()->publishedAt);
        $this->assertSame(PostStatus::Draft, $post->statusAt($this->t0));
    }

    public function test_publishing_without_a_date_stamps_now(): void
    {
        $post = Post::create($this->content(true), RenderedBody::empty(), null, 'u1', $this->t0);

        $this->assertEquals($this->t0, $post->content()->publishedAt);
        $this->assertSame(PostStatus::Published, $post->statusAt($this->t0));
    }

    public function test_an_explicit_future_date_schedules_the_post(): void
    {
        $post = Post::create($this->content(true, $this->t0->modify('+1 day')), RenderedBody::empty(), null, 'u1', $this->t0);

        $this->assertSame(PostStatus::Scheduled, $post->statusAt($this->t0));
        $this->assertSame(PostStatus::Published, $post->statusAt($this->t0->modify('+1 day')));
    }

    public function test_editing_a_published_post_keeps_its_date(): void
    {
        $post = Post::create($this->content(true), RenderedBody::empty(), null, 'u1', $this->t0);

        $post->replaceContent($this->content(true), RenderedBody::empty(), 'u2', $this->t0->modify('+2 years'));

        $this->assertEquals($this->t0, $post->content()->publishedAt, 'a typo fix must not re-date the post');
        $this->assertSame('u2', $post->updatedBy());
        $this->assertSame('u1', $post->createdBy());
    }

    public function test_unpublishing_and_republishing_restores_the_original_date(): void
    {
        $post = Post::create($this->content(true), RenderedBody::empty(), null, 'u1', $this->t0);

        $post->replaceContent($this->content(false), RenderedBody::empty(), 'u1', $this->t0->modify('+1 day'));
        $this->assertSame(PostStatus::Draft, $post->statusAt($this->t0->modify('+1 day')));

        $post->replaceContent($this->content(true), RenderedBody::empty(), 'u1', $this->t0->modify('+2 days'));
        $this->assertEquals($this->t0, $post->content()->publishedAt);
    }

    public function test_an_explicit_date_on_edit_replaces_the_old_one(): void
    {
        $post = Post::create($this->content(true), RenderedBody::empty(), null, 'u1', $this->t0);
        $new  = $this->t0->modify('+3 days');

        $post->replaceContent($this->content(true, $new), RenderedBody::empty(), 'u1', $this->t0->modify('+1 day'));

        $this->assertEquals($new, $post->content()->publishedAt);
    }

    public function test_announcement_is_cleared_when_a_post_stops_being_visible(): void
    {
        $post = Post::create($this->content(true), RenderedBody::empty(), null, 'u1', $this->t0);
        $this->assertTrue($post->needsAnnouncementAt($this->t0));
        $post->markAnnounced($this->t0);
        $this->assertFalse($post->needsAnnouncementAt($this->t0));

        // Moved into the future: scheduled again, so it must be announced again.
        $post->replaceContent($this->content(true, $this->t0->modify('+1 day')), RenderedBody::empty(), 'u1', $this->t0);
        $this->assertNull($post->announcedAt());
        $this->assertFalse($post->needsAnnouncementAt($this->t0));
        $this->assertTrue($post->needsAnnouncementAt($this->t0->modify('+1 day')));
    }

    public function test_trash_hides_the_post_and_restore_brings_it_back_as_it_was(): void
    {
        $post = Post::create($this->content(true), RenderedBody::empty(), null, 'u1', $this->t0);
        $post->markAnnounced($this->t0);

        $post->trash('u2', $this->t0);
        $this->assertSame(PostStatus::Trashed, $post->statusAt($this->t0));
        $this->assertNull($post->announcedAt());

        $post->restore('u2', $this->t0);
        $this->assertSame(PostStatus::Published, $post->statusAt($this->t0));
        $this->assertEquals($this->t0, $post->content()->publishedAt);
    }

    public function test_a_partial_post_refuses_to_be_edited(): void
    {
        $partial = Post::reconstitute([
            'public_id' => '01J00000000000000000000001', 'kind' => 'blog', 'slug' => 'x', 'title' => 'X',
            'summary' => 'Teaser', 'reading_minutes' => 4,
        ]);

        $this->assertTrue($partial->isPartial());
        $this->assertSame('Teaser', $partial->summary(), 'stored derived values are kept, not recomputed from a missing body');
        $this->assertSame(4, $partial->readingMinutes());

        $this->expectException(\LogicException::class);
        $partial->replaceContent($this->content(false), RenderedBody::empty(), 'u1', $this->t0);
    }
}
