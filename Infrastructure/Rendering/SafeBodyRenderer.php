<?php

declare(strict_types=1);

namespace Plugins\Posts\Infrastructure\Rendering;

use League\CommonMark\GithubFlavoredMarkdownConverter;
use Plugins\Posts\Application\Contracts\BodyRendererContract;
use Plugins\Posts\Domain\ValueObjects\BodyFormat;
use Plugins\Posts\Domain\ValueObjects\PostSlug;
use Plugins\Posts\Domain\ValueObjects\RenderedBody;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * SafeBodyRenderer — markdown / html / text → safe HTML + table of contents.
 *
 * Three layers, and the order is the point:
 *
 *   1. FORMAT   markdown → HTML through league/commonmark (GitHub flavour:
 *               tables, strikethrough, autolinks, task lists) with raw HTML
 *               ESCAPED and unsafe links dropped; text → escaped paragraphs;
 *               html → passed on as written.
 *   2. SANITISE every format goes through symfony/html-sanitizer — markdown
 *               too, as defence in depth. Only the W3C "safe" element set
 *               survives; links may be http(s)/mailto/relative, media
 *               http(s)/relative. This is the step that makes the output safe;
 *               nothing after it may add anything an author controls.
 *   3. ENRICH   on the sanitised tree: ids on h2–h4 (collected as the TOC),
 *               lazy-loading images, `rel="noopener noreferrer"` on links
 *               leaving the site, task-list checkboxes forced to disabled, and
 *               a paragraph holding nothing but a YouTube or Vimeo link turned
 *               into a privacy-friendly embed. Every attribute added here is
 *               generated from a validated value, never copied from input.
 *
 * Stateless and side-effect free — safe to share across requests.
 */
final class SafeBodyRenderer implements BodyRendererContract
{
    private const int MAX_INPUT = 250_000;

    private ?GithubFlavoredMarkdownConverter $markdown = null;

    private ?HtmlSanitizer $sanitizer = null;

    public function render(string $body, BodyFormat $format): RenderedBody
    {
        if (trim($body) === '') {
            return RenderedBody::empty();
        }

        $html = match ($format) {
            BodyFormat::Markdown => $this->markdown()->convert($body)->getContent(),
            BodyFormat::Html     => $body,
            BodyFormat::Text     => self::textToHtml($body),
        };

        return $this->enrich($this->sanitizer()->sanitize($html));
    }

    private function markdown(): GithubFlavoredMarkdownConverter
    {
        return $this->markdown ??= new GithubFlavoredMarkdownConverter([
            'html_input'         => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level'  => 50,
        ]);
    }

    private function sanitizer(): HtmlSanitizer
    {
        return $this->sanitizer ??= new HtmlSanitizer(
            (new HtmlSanitizerConfig())
                ->allowSafeElements()
                ->allowLinkSchemes(['https', 'http', 'mailto'])
                ->allowRelativeLinks()
                ->allowMediaSchemes(['https', 'http'])
                ->allowRelativeMedias()
                // Fenced code keeps its `language-php` class for a highlighter.
                ->allowAttribute('class', ['code', 'pre'])
                // GFM task lists; type and state are re-checked in enrich().
                ->allowElement('input', ['type', 'checked', 'disabled'])
                ->withMaxInputLength(self::MAX_INPUT),
        );
    }

    private static function textToHtml(string $text): string
    {
        $paragraphs = preg_split('/\R{2,}/u', trim($text)) ?: [];

        return implode('', array_map(
            static fn(string $p): string => '<p>' . nl2br(htmlspecialchars(trim($p), ENT_QUOTES | ENT_HTML5, 'UTF-8'), false) . '</p>',
            $paragraphs,
        ));
    }

    private function enrich(string $html): RenderedBody
    {
        if (trim($html) === '') {
            return RenderedBody::empty();
        }

        $doc  = \Dom\HTMLDocument::createFromString(
            '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>',
            LIBXML_NOERROR,
        );
        $body = $doc->body;
        if ($body === null) {
            return RenderedBody::empty();
        }

        $toc  = [];
        $used = [];
        foreach ($body->querySelectorAll('h2, h3, h4') as $heading) {
            $text = trim((string) $heading->textContent);
            if ($text === '') {
                continue;
            }

            $id = PostSlug::normalise($text) ?: 'section';
            $base = $id;
            for ($n = 2; isset($used[$id]); $n++) {
                $id = $base . '-' . $n;
            }
            $used[$id] = true;

            $heading->setAttribute('id', $id);
            $toc[] = ['level' => (int) substr(strtolower($heading->tagName), 1), 'id' => $id, 'text' => $text];
        }

        foreach ($body->querySelectorAll('img') as $img) {
            // The sanitiser drops an unsafe src (data:, javascript:) but keeps
            // the element; an image with nothing to show is just a broken box.
            if (trim((string) $img->getAttribute('src')) === '') {
                $img->remove();
                continue;
            }
            $img->setAttribute('loading', 'lazy');
            $img->setAttribute('decoding', 'async');
        }

        foreach ($body->querySelectorAll('a[href]') as $link) {
            if (preg_match('#^(?:https?:)?//#i', (string) $link->getAttribute('href')) === 1) {
                $link->setAttribute('rel', 'noopener noreferrer');
            }
        }

        foreach ($body->querySelectorAll('input') as $input) {
            if (strtolower((string) $input->getAttribute('type')) !== 'checkbox') {
                $input->remove();
                continue;
            }
            $input->setAttribute('disabled', '');
        }

        foreach ($body->querySelectorAll('p') as $paragraph) {
            $embed = self::embedFor($paragraph);
            if ($embed !== null) {
                $figure = $doc->createElement('figure');
                $figure->setAttribute('class', 'post-embed');
                $iframe = $doc->createElement('iframe');
                $iframe->setAttribute('src', $embed);
                $iframe->setAttribute('loading', 'lazy');
                $iframe->setAttribute('allowfullscreen', '');
                $iframe->setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
                $iframe->setAttribute('allow', 'encrypted-media; picture-in-picture');
                $iframe->setAttribute('title', 'Embedded video');
                $figure->appendChild($iframe);
                $paragraph->replaceWith($figure);
            }
        }

        return new RenderedBody(trim($body->innerHTML), $toc);
    }

    /**
     * The embed URL for a paragraph that is ONLY a YouTube or Vimeo link, or
     * null. The video id is matched against a strict pattern and the embed URL
     * built from it, so nothing from the link text or href reaches `src`.
     */
    private static function embedFor(\Dom\Element $paragraph): ?string
    {
        $text = trim((string) $paragraph->textContent);
        if ($text === '' || $paragraph->children->length > 1) {
            return null;
        }

        $only = $paragraph->firstElementChild;
        if ($only !== null && ($only->tagName !== 'A' || trim((string) $only->textContent) !== $text)) {
            return null;
        }
        $url = $only !== null ? (string) $only->getAttribute('href') : $text;

        if (preg_match('#^https?://(?:www\.|m\.)?(?:youtube\.com/watch\?(?:[^ ]*&)?v=|youtu\.be/|youtube\.com/shorts/)([A-Za-z0-9_-]{11})(?:[&?\#][^ ]*)?$#', $url, $m) === 1) {
            return 'https://www.youtube-nocookie.com/embed/' . $m[1];
        }

        if (preg_match('#^https?://(?:www\.)?vimeo\.com/(\d{1,12})(?:[/?\#][^ ]*)?$#', $url, $m) === 1) {
            return 'https://player.vimeo.com/video/' . $m[1] . '?dnt=1';
        }

        return null;
    }
}
