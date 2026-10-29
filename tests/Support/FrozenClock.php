<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Posts\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;

/**
 * A ClockPort frozen at a fixed instant — makes status-boundary behaviour
 * (published_at == now) deterministic instead of racing the
 * real clock.
 */
final readonly class FrozenClock implements ClockPort
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
}
