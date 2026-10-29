<?php

declare(strict_types=1);

namespace Plugins\Posts\API\Exceptions;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\HttpStatusAware;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;

/**
 * The post changed after the caller loaded it: someone else saved in between.
 * Answered as 409 so the editor can say so instead of silently overwriting
 * the other person's work. Carries the version now stored, so a client can
 * reload, or resend deliberately against that version.
 */
final class PostEditConflictException extends ServiceException implements HttpStatusAware
{
    public function __construct(string $publicId, public readonly int $currentVersion, ?\Throwable $previous = null)
    {
        parent::__construct(
            'Someone else saved this post after you opened it. Reload to see their changes before saving yours.',
            layer: 'post.edit_conflict',
            context: ['publicId' => $publicId, 'currentVersion' => $currentVersion],
            previous: $previous,
        );
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
