<?php

declare(strict_types=1);

namespace Plugins\Posts\Domain\ValueObjects;

/**
 * PostLocale — the language a post is written in (`posts.locale`, varchar(15)).
 *
 * The empty locale is real and is the default: a single-language site never
 * thinks about it, and its posts all live in `''`. A multilingual site tags
 * each post (`en`, `fr`, `pt-BR`) and links translations through a shared
 * translation group.
 *
 * Normalised to BCP 47 casing — language lower-case, region upper-case,
 * script title-case (`EN-us` → `en-US`, `zh-hant-tw` → `zh-Hant-TW`) — so
 * `?locale=en-us` and an editor's `en-US` are the same stream.
 *
 * A slug is unique per (kind, locale): `/fr/blog/lancement` and
 * `/blog/launch` are independent.
 */
final readonly class PostLocale
{
    public const int MAX_LENGTH = 15;

    private const string PATTERN = '/^[a-z]{2,3}(?:-[a-z0-9]{2,8}){0,3}$/i';

    private function __construct(private string $value)
    {
    }

    public static function none(): self
    {
        return new self('');
    }

    /** null/'' → the default (empty) locale. */
    public static function fromNullable(mixed $value): self
    {
        if (is_array($value) || is_object($value)) {
            throw new \DomainException('Locale must be text.');
        }

        $trimmed = str_replace('_', '-', trim((string) ($value ?? '')));

        if ($trimmed === '') {
            return self::none();
        }

        if (strlen($trimmed) > self::MAX_LENGTH || preg_match(self::PATTERN, $trimmed) !== 1) {
            throw new \DomainException('Locale must be a language tag such as "en", "fr" or "pt-BR".');
        }

        $parts = explode('-', $trimmed);
        $tag   = [strtolower(array_shift($parts))];

        foreach ($parts as $part) {
            $tag[] = match (true) {
                strlen($part) === 4 && ctype_alpha($part) => ucfirst(strtolower($part)), // script
                strlen($part) === 2 && ctype_alpha($part) => strtoupper($part),          // region
                default                                   => strtolower($part),
            };
        }

        return new self(implode('-', $tag));
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isNone(): bool
    {
        return $this->value === '';
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
