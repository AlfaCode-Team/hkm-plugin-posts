<?php

declare(strict_types=1);

namespace Plugins\Posts\Infrastructure\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;
use Plugins\Posts\Application\Services\PostEditorialService;
use Plugins\Posts\Application\Support\PostsConfig;

/**
 * Route filter `posts.editor` — the door to the admin API.
 *
 * 401 for a guest, 403 for a signed-in user holding none of the posts
 * permissions. Passing it only means "may use the admin API at all"; what the
 * user may do to a GIVEN post is decided per action by PostEditorialService.
 *
 * Registered by the plugin itself, so the admin routes need neither the
 * SecurityFilters plugin's `auth` alias nor any project wiring. Resolved once
 * per worker by the FilterRegistry, so it holds no request state.
 */
final class RequirePostsEditorStage implements HttpStageContract
{
    public const string ALIAS = 'posts.editor';

    public function handle(Request $request, callable $next): Response
    {
        $identity = $request->identity();

        if ($identity === null || $identity->isGuest()) {
            return Response::unauthorized('Sign in to manage posts.')->withHeader('Cache-Control', 'no-store');
        }

        $config = PostsConfig::fromEnvironment();
        if (!PostEditorialService::isEditor($identity, $config->writePermission, $config->publishPermission, $config->managePermission)) {
            return Response::forbidden('You do not have permission to manage posts.')->withHeader('Cache-Control', 'no-store');
        }

        return $next($request);
    }
}
