<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Posts\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;

/** A clock a test can move forward — for scheduling, trash age and preview expiry. */
final class MutableClock implements ClockPort
{
    public function __construct(private \DateTimeImmutable $now)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function timestamp(): int
    {
        return $this->now->getTimestamp();
    }

    public function set(string $instant): void
    {
        $this->now = new \DateTimeImmutable($instant);
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
