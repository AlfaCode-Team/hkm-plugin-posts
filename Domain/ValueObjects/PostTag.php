<?php

declare(strict_types=1);

namespace Plugins\Posts\Domain\ValueObjects;

/**
 * PostTag — one label on a post (`post_tags.tag`, varchar(40)).
 *
 * Normalised to the same shape as a slug (`Mobile Money` → `mobile-money`),
 * so `?tag=mobile-money` in a URL finds what an editor typed as
 * "Mobile Money", and two spellings of one tag never split a tag cloud.
 */
final readonly class PostTag
{
    public const int MAX_LENGTH = 40;

    /** A post is labelled, not indexed — past this, tags stop meaning anything. */
    public const int MAX_PER_POST = 10;

    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $tag = PostSlug::normalise($value);

        if ($tag === '') {
            throw new \DomainException('Tag must contain at least one letter or digit.');
        }

        if (strlen($tag) > self::MAX_LENGTH) {
            throw new \DomainException('Tag must not exceed ' . self::MAX_LENGTH . ' characters.');
        }

        return new self($tag);
    }

    public function value(): string
    {
        return $this->value;
    }
}
