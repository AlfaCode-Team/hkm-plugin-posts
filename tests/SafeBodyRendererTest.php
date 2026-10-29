<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Posts;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Posts\Domain\ValueObjects\BodyFormat;
use Plugins\Posts\Infrastructure\Rendering\SafeBodyRenderer;

/**
 * The renderer's one promise: whatever goes in, what comes out is safe to
 * print. Each payload below is a known way to run script through a content
 * field.
 */
#[CoversClass(SafeBodyRenderer::class)]
final class SafeBodyRendererTest extends TestCase
{
    private SafeBodyRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new SafeBodyRenderer();
    }

    /** @return iterable<string, array{string, BodyFormat}> */
    public static function attacks(): iterable
    {
        foreach ([BodyFormat::Markdown, BodyFormat::Html] as $format) {
            $f = $format->value;
            yield "{$f}: script tag"          => ['<script>alert(1)</script>', $format];
            yield "{$f}: img onerror"         => ['<img src=x onerror=alert(1)>', $format];
            yield "{$f}: javascript link"     => ['<a href="javascript:alert(1)">x</a>', $format];
            yield "{$f}: encoded js link"     => ['<a href="jav&#x09;ascript:alert(1)">x</a>', $format];
            yield "{$f}: svg script"          => ['<svg><script>alert(1)</script></svg>', $format];
            yield "{$f}: iframe"              => ['<iframe src="https://evil.test"></iframe>', $format];
            yield "{$f}: style attribute"     => ['<p style="background:url(javascript:alert(1))">x</p>', $format];
            yield "{$f}: data image"          => ['<img src="data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=">', $format];
            yield "{$f}: form + text input"   => ['<form action="https://evil.test"><input type="text" name="pw"></form>', $format];
            yield "{$f}: object embed"        => ['<object data="x.swf"></object><embed src="x.swf">', $format];
            yield "{$f}: meta refresh"        => ['<meta http-equiv="refresh" content="0;url=https://evil.test">', $format];
        }
        yield 'markdown syntax: javascript link' => ['[click](javascript:alert(1))', BodyFormat::Markdown];
        yield 'markdown syntax: js image' => ['![x](javascript:alert(1))', BodyFormat::Markdown];
        yield 'text: script'              => ['<script>alert(1)</script>', BodyFormat::Text];
    }

    #[DataProvider('attacks')]
    public function test_nothing_executable_survives(string $input, BodyFormat $format): void
    {
        $html = $this->renderer->render($input, $format)->html;

        // Inspect the PARSED output: in markdown, raw HTML is escaped into
        // visible text ("&lt;script&gt;"), which is harmless — only real
        // elements and attributes can execute.
        $doc = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><body>' . $html . '</body>', LIBXML_NOERROR);

        foreach (['script', 'iframe', 'object', 'embed', 'form', 'meta', 'style', 'base', 'link'] as $tag) {
            $this->assertCount(0, $doc->body->querySelectorAll($tag), "<{$tag}> survived in: {$html}");
        }

        foreach ($doc->body->querySelectorAll('*') as $element) {
            foreach ($element->attributes as $attribute) {
                $name  = strtolower($attribute->name);
                $value = strtolower(preg_replace('/[\s\x00-\x1f]+/', '', $attribute->value) ?? '');

                $this->assertStringStartsNotWith('on', $name, "event handler [{$name}] survived in: {$html}");
                $this->assertNotSame('style', $name, "style attribute survived in: {$html}");
                $this->assertStringStartsNotWith('javascript:', $value, "javascript: URL survived in: {$html}");
                $this->assertStringStartsNotWith('data:', $value, "data: URL survived in: {$html}");
            }
        }

        foreach ($doc->body->querySelectorAll('input') as $input) {
            $this->assertSame('checkbox', $input->getAttribute('type'));
            $this->assertTrue($input->hasAttribute('disabled'));
        }
    }

    public function test_raw_html_in_markdown_is_shown_as_text_not_run(): void
    {
        $html = $this->renderer->render('<script>alert(1)</script>', BodyFormat::Markdown)->html;

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_markdown_features_survive_sanitising(): void
    {
        $out = $this->renderer->render(
            "| a | b |\n|---|---|\n| 1 | 2 |\n\n- [x] done\n\n```php\necho 1;\n```\n\n~~old~~ [ext](https://example.test) ![pic](/a.png)",
            BodyFormat::Markdown,
        )->html;

        $this->assertStringContainsString('<table>', $out);
        $this->assertStringContainsString('<input checked="" disabled="" type="checkbox">', $out);
        $this->assertStringContainsString('<code class="language-php">', $out);
        $this->assertStringContainsString('<del>old</del>', $out);
        $this->assertStringContainsString('<a href="https://example.test" rel="noopener noreferrer">ext</a>', $out);
        $this->assertStringContainsString('<img src="/a.png" alt="pic" loading="lazy" decoding="async">', $out);
    }

    public function test_a_lone_video_link_becomes_a_privacy_friendly_embed(): void
    {
        $youtube = $this->renderer->render('https://youtu.be/dQw4w9WgXcQ', BodyFormat::Markdown)->html;
        $vimeo   = $this->renderer->render('https://vimeo.com/76979871', BodyFormat::Markdown)->html;
        $inline  = $this->renderer->render('See https://youtu.be/dQw4w9WgXcQ here', BodyFormat::Markdown)->html;

        $this->assertStringContainsString('src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"', $youtube);
        $this->assertStringContainsString('src="https://player.vimeo.com/video/76979871?dnt=1"', $vimeo);
        $this->assertStringNotContainsString('<iframe', $inline, 'only a link standing alone is embedded');
    }

    public function test_a_lookalike_video_url_is_not_embedded(): void
    {
        $html = $this->renderer->render('https://youtube.com.evil.test/watch?v=dQw4w9WgXcQ', BodyFormat::Markdown)->html;

        $this->assertStringNotContainsString('<iframe', $html);
    }

    public function test_text_format_escapes_and_keeps_paragraphs(): void
    {
        $this->assertSame("<p>a &lt;b&gt;</p><p>line1<br>\nline2</p>", $this->renderer->render("a <b>\n\nline1\nline2", BodyFormat::Text)->html);
    }

    public function test_empty_body_renders_to_nothing(): void
    {
        $this->assertSame('', $this->renderer->render("  \n ", BodyFormat::Markdown)->html);
        $this->assertSame([], $this->renderer->render("  \n ", BodyFormat::Markdown)->toc);
    }
}
