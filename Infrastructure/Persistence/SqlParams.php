<?php

declare(strict_types=1);

namespace Plugins\Posts\Infrastructure\Persistence;

/**
 * SqlParams — names placeholders for ONE statement: `:p0`, `:p1`, …
 *
 * Exists because a driver with native prepares (PostgreSQL, MySQL with
 * emulation off) rejects a statement that uses one named placeholder twice,
 * and dynamically built SQL — IN lists, optional filters, a visibility
 * predicate used inside a subquery — makes hand-picked names collide. Every
 * value goes through `add()`, so every placeholder is new.
 *
 * Create one per statement; never share an instance across statements.
 */
final class SqlParams
{
    /** @var array<string, mixed> */
    private array $values = [];

    /** Bind a value; returns its placeholder (`:p3`). */
    public function add(mixed $value): string
    {
        $name                = 'p' . count($this->values);
        $this->values[$name] = $value;

        return ':' . $name;
    }

    /**
     * Bind each value; returns the comma-separated placeholders for an IN (…).
     *
     * @param list<string|int> $values Must not be empty — `IN ()` is a syntax error.
     */
    public function list(array $values): string
    {
        if ($values === []) {
            throw new \LogicException('An IN list needs at least one value.');
        }

        return implode(', ', array_map($this->add(...), array_values($values)));
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }
}
