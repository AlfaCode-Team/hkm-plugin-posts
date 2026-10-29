<?php

declare(strict_types=1);

namespace Plugins\Posts\Domain\ValueObjects;

/**
 * PostTitle — the required headline (`posts.title`, varchar(200)).
 */
final readonly class PostTitle
{
    public const int MAX_LENGTH = 200;

    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new \DomainException('Title is required.');
        }

        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw new \DomainException('Title must not exceed ' . self::MAX_LENGTH . ' characters.');
        }

        return new self($trimmed);
    }

    public function value(): string
    {
        return $this->value;
    }
}
