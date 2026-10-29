<?php

declare(strict_types=1);

namespace Plugins\Posts\API\IntegrationEvents;

/**
 * `post.deleted` — A post was permanently deleted (purged). Its id will never resolve again.
 */
final readonly class PostDeletedIntegrationEvent extends PostIntegrationEvent
{
    public const string NAME = 'post.deleted';

    public function name(): string
    {
        return self::NAME;
    }
}
