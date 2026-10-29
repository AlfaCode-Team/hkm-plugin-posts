<?php

declare(strict_types=1);

namespace Plugins\Posts\Domain\Rules;

/**
 * PostScheduleRule — parses the optional publication instant (`posts.published_at`, UTC).
 */
final class PostScheduleRule
{
    private function __construct()
    {
        // Static rule holder — never instantiated.
    }

    /**
     * null/'' → no date. Otherwise an ISO-8601 string WITH an offset
     * (`2026-11-21T09:00:00+03:00`, the shape `Date.toISOString()` produces),
     * normalised to UTC.
     *
     * The offset is required, not assumed: a bare `2026-11-21T09:00` would be
     * read in the server's timezone, so the same form submitted to two servers
     * would schedule the post at two different instants.
     */
    public static function parseInstant(mixed $value): ?\DateTimeImmutable
    {
        if (is_array($value) || is_object($value)) {
            throw new \DomainException('Publication date must be an ISO-8601 date/time.');
        }

        $trimmed = trim((string) ($value ?? ''));

        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $trimmed) !== 1) {
            throw new \DomainException('Publication date must include a timezone offset (e.g. 2026-11-21T09:00:00+00:00).');
        }

        try {
            $instant = new \DateTimeImmutable($trimmed);
        } catch (\Exception) {
            throw new \DomainException('Publication date must be a valid ISO-8601 date/time.');
        }

        return $instant->setTimezone(new \DateTimeZone('UTC'));
    }
}
