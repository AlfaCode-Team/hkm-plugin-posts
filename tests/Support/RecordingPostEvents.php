<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Posts\Support;

use Plugins\Posts\API\IntegrationEvents\PostIntegrationEvent;
use Plugins\Posts\Application\Contracts\PostEventsContract;

/** Records every event the service sends, in order. */
final class RecordingPostEvents implements PostEventsContract
{
    /** @var list<PostIntegrationEvent> */
    public array $events = [];

    public function publish(PostIntegrationEvent $event): void
    {
        $this->events[] = $event;
    }

    /** @return list<string> "name:postId" in dispatch order */
    public function names(): array
    {
        return array_map(static fn(PostIntegrationEvent $e): string => $e->name(), $this->events);
    }

    public function clear(): void
    {
        $this->events = [];
    }
}
