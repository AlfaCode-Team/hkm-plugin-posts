<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Posts;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use Plugins\Posts\API\DTO\SiteInfo;
use Plugins\Posts\Application\Services\PostSyndicationService;
use Tests\Unit\Plugins\Posts\Support\PostsTestCase;

/**
 * Feeds, sitemaps, structured data and head tags. Every XML document is
 * PARSED, so a malformed one fails here rather than in a feed reader.
 */
#[CoversClass(PostSyndicationService::class)]
final class PostSyndicationTest extends PostsTestCase
{
    private const string NASTY = 'Rates <script>alert(1)</script> & "quotes" ]]> done';

    private SiteInfo $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = new SiteInfo('Test Site', 'https://example.test/', 'News and notes', '/logo.png', '@testsite');
    }

    private static function parse(string $xml): \SimpleXMLElement
    {
        $doc = simplexml_load_string($xml);
        self::assertNotFalse($doc, "not well-formed XML:\n{$xml}");

        return $doc;
    }

    public function test_rss_is_valid_escaped_and_public_only(): void
    {
        $this->make(['title' => self::NASTY, 'body' => "Body with ]]> inside\n\n## Part"]);
        $this->make(['title' => 'Draft', 'published' => false]);

        $xml  = $this->syndication->feed('rss', $this->now(), $this->site, 'https://example.test/feeds/posts/rss');
        $rss  = self::parse($xml);
        $item = $rss->channel->item[0];

        $this->assertCount(1, $rss->channel->item, 'drafts never syndicate');
        $this->assertSame(self::NASTY, (string) $item->title);
        $this->assertSame('https://example.test/blog/rates-script-alert-1-script-quotes-done', (string) $item->link);
        $content = (string) $item->children('http://purl.org/rss/1.0/modules/content/')->encoded;
        $this->assertStringContainsString('Body with ]]&gt; inside', $content, 'a CDATA terminator in the body cannot end the section');
        $this->assertStringContainsString('<h2 id="part">Part</h2>', $content);
        $this->assertSame('/storage/covers/hello.webp' !== '' ? 'https://example.test/storage/covers/hello.webp' : '', (string) $item->enclosure['url']);
    }

    public function test_atom_and_json_feed_are_valid(): void
    {
        $this->make(['title' => self::NASTY]);

        $atom = self::parse($this->syndication->feed('atom', $this->now(), $this->site, 'https://example.test/feeds/posts/atom'));
        $this->assertSame(self::NASTY, (string) $atom->entry[0]->title);
        $this->assertSame('https://example.test/feeds/posts/atom', (string) $atom->id);

        $json = json_decode($this->syndication->feed('json', $this->now(), $this->site, 'https://example.test/feeds/posts/json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('https://jsonfeed.org/version/1.1', $json['version']);
        $this->assertSame(self::NASTY, $json['items'][0]['title']);
        $this->assertSame(['news', 'mobile-money'], $json['items'][0]['tags']);
        $this->assertSame([['name' => 'Jane Doe']], $json['items'][0]['authors']);
    }

    public function test_feeds_filter_and_reject_unknown_formats(): void
    {
        $this->make(['title' => 'Blog']);
        $this->make(['title' => 'Press', 'kind' => 'press']);

        $rss = self::parse($this->syndication->feed('rss', $this->now(), $this->site, 'https://x.test/f', kind: 'press'));
        $this->assertCount(1, $rss->channel->item);
        $this->assertSame('Test Site — Press', (string) $rss->channel->title);

        $empty = self::parse($this->syndication->feed('rss', $this->now(), $this->site, 'https://x.test/f', tag: '!!!'));
        $this->assertCount(0, $empty->channel->item, 'a tag that normalises to nothing matches nothing');

        $this->expectException(ValidationException::class);
        $this->syndication->feed('pdf', $this->now(), $this->site, 'https://x.test/f');
    }

    public function test_sitemap_lists_public_indexable_posts_with_alternates_and_images(): void
    {
        $en = $this->make(['title' => 'Hello']);
        $this->posts->create($this->input(['title' => 'Bonjour', 'locale' => 'fr', 'translationOf' => $en->id]), self::ACTOR);
        $this->make(['title' => 'Hidden', 'noindex' => true]);
        $this->make(['title' => 'Draft', 'published' => false]);

        $xml = self::parse($this->syndication->sitemap($this->now(), $this->site));
        $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xml->registerXPathNamespace('x', 'http://www.w3.org/1999/xhtml');
        $xml->registerXPathNamespace('i', 'http://www.google.com/schemas/sitemap-image/1.1');

        $locs = array_map('strval', $xml->xpath('//s:url/s:loc'));
        sort($locs);
        $this->assertSame(['https://example.test/blog/hello', 'https://example.test/fr/blog/bonjour'], $locs);
        $this->assertSame(['en', 'fr', 'en', 'fr'], array_map(static fn($l) => (string) $l['hreflang'], $xml->xpath('//x:link')));
        $this->assertCount(2, $xml->xpath('//i:image/i:loc'));
        $this->assertContains($en->updatedAt, array_map('strval', $xml->xpath('//s:url/s:lastmod')), 'lastmod is the post\'s own updatedAt');
    }

    public function test_news_sitemap_covers_the_last_two_days_of_news_kinds(): void
    {
        $this->make(['title' => 'Fresh release', 'kind' => 'press', 'publishedAt' => '2026-09-30T12:00:00Z']);
        $this->make(['title' => 'Old release', 'kind' => 'press', 'publishedAt' => '2026-09-20T12:00:00Z']);
        $this->make(['title' => 'Fresh blog', 'kind' => 'blog']);

        $xml = self::parse($this->syndication->newsSitemap($this->now(), $this->site));
        $xml->registerXPathNamespace('n', 'http://www.google.com/schemas/sitemap-news/0.9');

        $this->assertSame(['Fresh release'], array_map('strval', $xml->xpath('//n:title')));
        $this->assertSame(['en'], array_map('strval', $xml->xpath('//n:language')));
    }

    public function test_structured_data_types_follow_the_kind(): void
    {
        $blog    = $this->syndication->structuredData($this->make(), $this->site);
        $article = $this->syndication->structuredData($this->make(['kind' => 'article']), $this->site);
        $press   = $this->syndication->structuredData($this->make(['kind' => 'press', 'dateline' => 'KAMPALA']), $this->site);

        $this->assertSame(['BlogPosting', 'Article', 'NewsArticle'], [$blog['@type'], $article['@type'], $press['@type']]);
        $this->assertSame('KAMPALA', $press['dateline']);
        $this->assertSame(['https://example.test/storage/covers/hello.webp'], $blog['image']);
        $this->assertSame('https://example.test/logo.png', $blog['publisher']['logo']['url']);
        $this->assertSame([['@type' => 'Person', 'name' => 'Jane Doe']], $blog['author']);
    }

    public function test_head_html_is_escaped_and_the_json_ld_cannot_break_out_of_its_script(): void
    {
        $post = $this->make(['title' => '</script><script>alert(1)</script>', 'metaTitle' => null, 'noindex' => true]);

        $head = $this->syndication->headHtml($post, $this->site);

        $this->assertSame(1, substr_count($head, '</script>'), 'only the closing tag of the JSON-LD block itself');
        $this->assertStringContainsString('<title>&lt;/script&gt;&lt;script&gt;alert(1)&lt;/script&gt; | Test Site</title>', $head);
        $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', $head);
        $this->assertStringContainsString('<meta property="og:image" content="https://example.test/storage/covers/hello.webp">', $head);
        $this->assertStringContainsString('<meta name="twitter:site" content="@testsite">', $head);
        $this->assertStringContainsString('<link rel="canonical" href="https://example.test/blog/', $head);

        preg_match('#<script type="application/ld\+json">(.*)</script>#s', $head, $m);
        $this->assertSame('</script><script>alert(1)</script>', json_decode($m[1], true)['headline']);
    }

    public function test_site_info_rejects_a_relative_base(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SiteInfo('X', '/not-absolute');
    }
}
