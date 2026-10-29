<?php

declare(strict_types=1);

namespace Plugins\Posts\API\IntegrationEvents;

/**
 * `post.updated` — A post that is visible to readers was edited and is still visible.
 */
final readonly class PostUpdatedIntegrationEvent extends PostIntegrationEvent
{
    public const string NAME = 'post.updated';

    public function name(): string
    {
        return self::NAME;
    }
}
