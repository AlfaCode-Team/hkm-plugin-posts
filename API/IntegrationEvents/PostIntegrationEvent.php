<?php

declare(strict_types=1);

namespace Plugins\Posts\API\IntegrationEvents;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;

/**
 * The shape every post lifecycle event shares. Primitives only, and only what
 * a listener needs to act — purge a cache, ping a sitemap, queue a newsletter:
 * which post, where it lives, and when. Never the body.
 *
 * Dispatched AFTER the write commits, never inside the transaction.
 */
abstract readonly class PostIntegrationEvent implements IntegrationEventContract
{
    public const string VERSION = '1.0';

    public function __construct(
        public string $postId,
        public string $kind,
        public string $locale,
        public string $slug,
        public string $path,
        public ?string $publishedAt,
        public string $occurredAt,
    ) {
    }

    public function version(): string
    {
        return self::VERSION;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'postId'      => $this->postId,
            'kind'        => $this->kind,
            'locale'      => $this->locale,
            'slug'        => $this->slug,
            'path'        => $this->path,
            'publishedAt' => $this->publishedAt,
            'occurredAt'  => $this->occurredAt,
            'version'     => self::VERSION,
        ];
    }
}
