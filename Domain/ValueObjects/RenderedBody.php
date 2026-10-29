<?php

declare(strict_types=1);

namespace Plugins\Posts\Domain\ValueObjects;

/**
 * RenderedBody — the body as SAFE HTML plus its table of contents
 * (`posts.body_html`, `posts.toc`).
 *
 * Produced once, when the post is saved, by the Infrastructure renderer —
 * markdown rendered with raw HTML escaped, html run through a sanitiser, text
 * escaped — so a read never renders and a host can print `html` as-is.
 *
 * The domain only carries it. It never produces it: rendering needs vendor
 * libraries, and Domain/ imports nothing from outside itself.
 */
final readonly class RenderedBody
{
    /**
     * @param list<array{level: int, id: string, text: string}> $toc Headings h2–h4, in order.
     */
    public function __construct(
        public string $html,
        public array $toc,
    ) {
    }

    public static function empty(): self
    {
        return new self('', []);
    }
}
