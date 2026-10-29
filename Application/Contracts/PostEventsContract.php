<?php

declare(strict_types=1);

namespace Plugins\Posts\Application\Contracts;

use Plugins\Posts\API\IntegrationEvents\PostIntegrationEvent;

/**
 * Where the service sends post lifecycle events.
 *
 * A port of this plugin's own rather than the kernel `EventBus` directly: the
 * bus is a final class that needs a container, and the service should be
 * testable without one. {@see \Plugins\Posts\Infrastructure\Events\EventBusPostEvents}
 * is the production adapter.
 */
interface PostEventsContract
{
    public function publish(PostIntegrationEvent $event): void;
}
