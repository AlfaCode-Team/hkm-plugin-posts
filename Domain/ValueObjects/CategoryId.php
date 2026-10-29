<?php

declare(strict_types=1);

namespace Plugins\Posts\Domain\ValueObjects;

/**
 * CategoryId — the PUBLIC identifier (`post_categories.public_id`).
 *
 * A ULID: 26-char Crockford base32, lexicographically sortable in the same
 * order it was generated. The internal `id` bigint auto-increment never
 * leaves the persistence layer, so this is the only id a caller ever sees.
 *
 * Self-contained on purpose. Every other plugin in this ecosystem that needs a
 * ULID rolls its own for the same reason (see
 * `Plugins\Feedback\Domain\ValueObjects\Ulid`, `Hkmvote\Domain\ValueObject\Ulid`):
 * the kernel ships none, and importing a sibling plugin's copy would couple
 * this domain to a plugin it has nothing to do with.
 */
final readonly class CategoryId
{
    /** Crockford base32: no I, L, O or U — the characters people misread. */
    private const string ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const int LENGTH = 26;

    private function __construct(private string $value)
    {
    }

    public static function generate(): self
    {
        $ms = (int) (microtime(true) * 1000);

        // 10 characters of timestamp (48 bits), 16 of randomness (80 bits).
        $time = '';
        for ($i = 9; $i >= 0; $i--) {
            $time = self::ALPHABET[$ms % 32] . $time;
            $ms = intdiv($ms, 32);
        }

        $random = '';
        for ($i = 0; $i < 16; $i++) {
            $random .= self::ALPHABET[random_int(0, 31)];
        }

        return new self($time . $random);
    }

    public static function fromString(string $value): self
    {
        $value = strtoupper(trim($value));

        if (strlen($value) !== self::LENGTH || strspn($value, self::ALPHABET) !== self::LENGTH) {
            throw new \DomainException('CategoryId must be a 26-character Crockford base32 ULID.');
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
