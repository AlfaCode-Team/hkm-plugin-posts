<?php

declare(strict_types=1);

namespace Plugins\Posts\API\IntegrationEvents;

/**
 * `post.published` — A post became visible to readers — on publish, on restore from the trash, or when its scheduled time arrived (sent by `hkm posts:publish-due`). Sent once per publication.
 */
final readonly class PostPublishedIntegrationEvent extends PostIntegrationEvent
{
    public const string NAME = 'post.published';

    public function name(): string
    {
        return self::NAME;
    }
}
