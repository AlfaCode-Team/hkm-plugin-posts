<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Posts\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Posts\Domain\Rules\PostLinkRule;
use Plugins\Posts\Domain\Rules\PostScheduleRule;
use Plugins\Posts\Domain\Rules\PostTextRule;
use Plugins\Posts\Domain\ValueObjects\BodyFormat;
use Plugins\Posts\Domain\ValueObjects\PostBody;
use Plugins\Posts\Domain\ValueObjects\PostKind;
use Plugins\Posts\Domain\ValueObjects\PostSlug;
use Plugins\Posts\Domain\ValueObjects\PostStatus;
use Plugins\Posts\Domain\ValueObjects\PostTag;
use Plugins\Posts\Domain\ValueObjects\PostTitle;

final class PostValueObjectsTest extends TestCase
{
    // ── PostKind ─────────────────────────────────────────────────────────

    public function test_kind_defaults_to_blog_and_is_case_insensitive(): void
    {
        $this->assertSame(PostKind::Blog, PostKind::fromNullable(null));
        $this->assertSame(PostKind::Blog, PostKind::fromNullable('  '));
        $this->assertSame(PostKind::Press, PostKind::fromNullable(' PRESS '));
        $this->assertSame(['blog', 'article', 'press'], PostKind::values());
    }

    public function test_unknown_kind_is_rejected(): void
    {
        $this->expectException(\DomainException::class);
        PostKind::fromNullable('podcast');
    }

    // ── PostTitle ────────────────────────────────────────────────────────

    public function test_title_is_required_and_bounded(): void
    {
        $this->assertSame('Launch', PostTitle::fromString('  Launch ')->value());
        $this->assertSame(str_repeat('a', 200), PostTitle::fromString(str_repeat('a', 200))->value());

        foreach (['   ', str_repeat('a', 201)] as $bad) {
            try {
                PostTitle::fromString($bad);
                $this->fail("Accepted an invalid title of length " . strlen($bad));
            } catch (\DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ── PostSlug ─────────────────────────────────────────────────────────

    /** @return iterable<string, array{string, string}> */
    public static function titles(): iterable
    {
        yield 'plain'          => ['Hello World', 'hello-world'];
        yield 'punctuation'    => ['  Q3 results: up 20%!  ', 'q3-results-up-20'];
        yield 'accents'        => ['Café à Kinshasa', 'cafe-a-kinshasa'];
        yield 'apostrophes'    => ["Don't stop — it’s live", 'dont-stop-its-live'];
        yield 'nothing usable' => ['!!! ???', 'post'];
    }

    #[DataProvider('titles')]
    public function test_slug_is_derived_from_a_title(string $title, string $expected): void
    {
        $this->assertSame($expected, PostSlug::fromTitle($title)->value());
    }

    public function test_derived_slug_fits_the_column(): void
    {
        $slug = PostSlug::fromTitle(str_repeat('word ', 100));

        $this->assertLessThanOrEqual(PostSlug::MAX_LENGTH, strlen($slug->value()));
        $this->assertStringEndsNotWith('-', $slug->value());
    }

    public function test_suffix_shortens_the_base_to_stay_within_the_column(): void
    {
        $long = PostSlug::fromString(str_repeat('a', PostSlug::MAX_LENGTH));

        $suffixed = $long->withSuffix(12);

        $this->assertSame(PostSlug::MAX_LENGTH, strlen($suffixed->value()));
        $this->assertStringEndsWith('-12', $suffixed->value());
        $this->assertSame('launch-2', PostSlug::fromString('launch')->withSuffix(2)->value());
    }

    /** @return iterable<string, array{string}> */
    public static function badSlugs(): iterable
    {
        yield 'upper case'      => ['Hello'];
        yield 'space'           => ['hello world'];
        yield 'double hyphen'   => ['hello--world'];
        yield 'leading hyphen'  => ['-hello'];
        yield 'trailing hyphen' => ['hello-'];
        yield 'slash'           => ['a/b'];
        yield 'dot dot'         => ['..'];
        yield 'too long'        => [str_repeat('a', 161)];
    }

    #[DataProvider('badSlugs')]
    public function test_editor_supplied_slug_is_validated_not_rewritten(string $slug): void
    {
        $this->expectException(\DomainException::class);
        PostSlug::fromString($slug);
    }

    // ── PostTag ──────────────────────────────────────────────────────────

    public function test_tags_are_normalised_to_slug_form(): void
    {
        $this->assertSame('mobile-money', PostTag::fromString('  Mobile Money ')->value());
        $this->assertSame('election-2026', PostTag::fromString('Election_2026')->value());
    }

    public function test_tag_with_no_letters_or_digits_is_rejected(): void
    {
        $this->expectException(\DomainException::class);
        PostTag::fromString('!!!');
    }

    public function test_overlong_tag_is_rejected(): void
    {
        $this->expectException(\DomainException::class);
        PostTag::fromString(str_repeat('a', 41));
    }

    // ── PostBody ─────────────────────────────────────────────────────────

    public function test_empty_body_is_none(): void
    {
        $this->assertNull(PostBody::fromNullable(null));
        $this->assertNull(PostBody::fromNullable("  \n "));
    }

    public function test_plain_text_strips_markdown_and_html(): void
    {
        $body = PostBody::fromNullable(
            "# Heading\n\nSome **bold** and a [link](https://x.test) and ![img](/a.png).\n\n<p>Para &amp; more</p>\n- item",
        );

        $this->assertSame('Heading Some bold and a link and . Para & more item', $body?->plainText());
    }

    public function test_reading_minutes_round_up_and_never_drop_below_one(): void
    {
        $this->assertSame(1, PostBody::fromNullable('one two three')?->readingMinutes());
        $this->assertSame(1, PostBody::fromNullable(trim(str_repeat('w ', 200)))?->readingMinutes());
        $this->assertSame(2, PostBody::fromNullable(trim(str_repeat('w ', 201)))?->readingMinutes());
    }

    public function test_body_format_defaults_to_markdown(): void
    {
        $this->assertSame(BodyFormat::Markdown, BodyFormat::fromNullable(null));
        $this->assertSame(BodyFormat::Html, BodyFormat::fromNullable('HTML'));

        $this->expectException(\DomainException::class);
        BodyFormat::fromNullable('rtf');
    }

    // ── PostStatus ───────────────────────────────────────────────────────

    public function test_status_boundaries(): void
    {
        $now = new \DateTimeImmutable('2026-10-01 12:00:00', new \DateTimeZone('UTC'));

        $this->assertSame(PostStatus::Draft, PostStatus::resolve(false, $now->modify('-1 day'), $now));
        $this->assertSame(PostStatus::Scheduled, PostStatus::resolve(true, $now->modify('+1 second'), $now));
        $this->assertSame(PostStatus::Published, PostStatus::resolve(true, $now, $now), 'published_at == now is out');
        $this->assertSame(PostStatus::Published, PostStatus::resolve(true, null, $now));
    }

    // ── Rules ────────────────────────────────────────────────────────────

    public function test_optional_text_trims_nulls_and_bounds(): void
    {
        $this->assertNull(PostTextRule::optional('   ', 10, 'X'));
        $this->assertSame('abc', PostTextRule::optional(' abc ', 10, 'X'));

        $this->expectException(\DomainException::class);
        PostTextRule::optional(str_repeat('a', 11), 10, 'X');
    }

    public function test_optional_text_rejects_a_list(): void
    {
        $this->expectException(\DomainException::class);
        PostTextRule::optional(['a'], 10, 'X');
    }

    /** @return iterable<string, array{string, bool}> */
    public static function imageSources(): iterable
    {
        yield 'https'               => ['https://cdn.test/a.webp', true];
        yield 'root relative'       => ['/storage/covers/a.webp', true];
        yield 'protocol relative'   => ['//evil.test/a.png', false];
        yield 'javascript'          => ['javascript:alert(1)', false];
        yield 'data'                => ['data:image/png;base64,AAAA', false];
        yield 'relative no slash'   => ['covers/a.webp', false];
        yield 'backslash path'      => ['/\\evil.test', false];
        yield 'space in path'       => ['/a b.png', false];
    }

    #[DataProvider('imageSources')]
    public function test_cover_image_accepts_only_http_or_root_relative(string $value, bool $ok): void
    {
        if (!$ok) {
            $this->expectException(\DomainException::class);
        }

        $this->assertSame($value, PostLinkRule::imageSource($value, 'Cover'));
    }

    public function test_source_url_must_be_absolute(): void
    {
        $this->assertSame('https://news.test/story', PostLinkRule::absoluteUrl('https://news.test/story', 'Source'));
        $this->assertNull(PostLinkRule::absoluteUrl('', 'Source'));

        $this->expectException(\DomainException::class);
        PostLinkRule::absoluteUrl('/story', 'Source');
    }

    public function test_publication_date_needs_an_offset_and_is_normalised_to_utc(): void
    {
        $instant = PostScheduleRule::parseInstant('2026-11-21T09:00:00+03:00');

        $this->assertSame('2026-11-21 06:00:00', $instant?->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $instant?->getTimezone()->getName());
        $this->assertSame('2026-11-21 09:00:00', PostScheduleRule::parseInstant('2026-11-21T09:00:00Z')?->format('Y-m-d H:i:s'));
        $this->assertNull(PostScheduleRule::parseInstant(null));

        $this->expectException(\DomainException::class);
        PostScheduleRule::parseInstant('2026-11-21T09:00:00');
    }
}
