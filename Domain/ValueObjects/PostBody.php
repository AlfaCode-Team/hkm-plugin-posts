<?php

declare(strict_types=1);

namespace Plugins\Posts\Domain\ValueObjects;

/**
 * PostBody — the long-form copy (`posts.body`, mediumtext nullable), stored
 * verbatim. See {@see BodyFormat} for why this class never renders or
 * sanitises it.
 */
final readonly class PostBody
{
    /** Roughly 30,000 words — far beyond any post, well inside a MEDIUMTEXT. */
    public const int MAX_LENGTH = 200_000;

    /** Average adult silent-reading speed, the figure reading-time badges use. */
    private const int WORDS_PER_MINUTE = 200;

    private function __construct(private string $value)
    {
    }

    /** null/'' → no body. Surrounding whitespace is trimmed; inner formatting is kept. */
    public static function fromNullable(mixed $value): ?self
    {
        $trimmed = trim((string) ($value ?? ''));

        if ($trimmed === '') {
            return null;
        }

        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw new \DomainException('Body must not exceed ' . self::MAX_LENGTH . ' characters.');
        }

        return new self($trimmed);
    }

    public function value(): string
    {
        return $this->value;
    }

    /**
     * The body with markup removed and whitespace collapsed — for a listing
     * card or a meta description, never for display as the post itself.
     * Markdown syntax is stripped approximately (headings, emphasis, code
     * fences, images, `[text](url)` links); it is a summary, not a renderer.
     */
    public function plainText(): string
    {
        $text = strip_tags($this->value);
        $text = (string) preg_replace('/!\[[^\]]*\]\([^)]*\)/', ' ', $text);  // images
        $text = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text); // links → text
        $text = (string) preg_replace('/^\s{0,3}(?:#{1,6}|>|[-*+]|\d+\.)\s+/m', '', $text);
        $text = str_replace(['```', '**', '__', '`', '~~'], '', $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * The first `$length` characters of the plain text, cut at a word, with
     * an ellipsis when anything was cut — a listing card's teaser.
     */
    public function summary(int $length = 200): string
    {
        $text = $this->plainText();

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        $cut   = mb_substr($text, 0, $length);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space === false ? $cut : mb_substr($cut, 0, $space), " \t.,;:") . '…';
    }

    public function readingMinutes(): int
    {
        $text = $this->plainText();

        if ($text === '') {
            return 1;
        }

        $words = count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);

        return max(1, (int) ceil($words / self::WORDS_PER_MINUTE));
    }
}
