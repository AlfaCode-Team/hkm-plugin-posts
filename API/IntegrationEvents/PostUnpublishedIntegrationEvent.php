<?php

declare(strict_types=1);

namespace Plugins\Posts\API\IntegrationEvents;

/**
 * `post.unpublished` — A visible post stopped being visible — unpublished, moved into the future, or trashed.
 */
final readonly class PostUnpublishedIntegrationEvent extends PostIntegrationEvent
{
    public const string NAME = 'post.unpublished';

    public function name(): string
    {
        return self::NAME;
    }
}
