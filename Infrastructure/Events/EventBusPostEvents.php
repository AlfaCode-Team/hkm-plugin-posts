<?php

declare(strict_types=1);

namespace Plugins\Posts\Infrastructure\Events;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use Plugins\Posts\API\IntegrationEvents\PostIntegrationEvent;
use Plugins\Posts\Application\Contracts\PostEventsContract;

/**
 * Sends post events through the kernel EventBus. The bus already isolates
 * listener failures (logs them, returns them) — a broken subscriber can never
 * undo a save that has committed — so the failures it returns are not
 * re-thrown here.
 */
final class EventBusPostEvents implements PostEventsContract
{
    public function __construct(
        private readonly EventBus $bus,
    ) {
    }

    public function publish(PostIntegrationEvent $event): void
    {
        $this->bus->dispatch($event);
    }
}
