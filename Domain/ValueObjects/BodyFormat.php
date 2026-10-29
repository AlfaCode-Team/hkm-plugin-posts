<?php

declare(strict_types=1);

namespace Plugins\Posts\Domain\ValueObjects;

/**
 * BodyFormat — how the host should interpret `posts.body`.
 *
 * This plugin STORES the body verbatim and never renders it. The format is a
 * label the host reads to pick a renderer; it is not a promise that the body
 * is safe. An `html` body is author-controlled markup — rendering it without
 * sanitising is stored XSS the moment an author is not fully trusted.
 */
enum BodyFormat: string
{
    case Markdown = 'markdown';
    case Html     = 'html';
    case Text     = 'text';

    /** null/'' → `markdown`. */
    public static function fromNullable(mixed $value): self
    {
        $trimmed = strtolower(trim((string) ($value ?? '')));

        if ($trimmed === '') {
            return self::Markdown;
        }

        return self::tryFrom($trimmed)
            ?? throw new \DomainException('Body format must be one of: markdown, html, text.');
    }
}
