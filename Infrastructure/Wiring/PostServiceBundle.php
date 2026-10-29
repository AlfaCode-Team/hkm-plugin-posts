<?php

declare(strict_types=1);

namespace Plugins\Posts\Infrastructure\Wiring;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Posts\Application\Contracts\BodyRendererContract;
use Plugins\Posts\Application\Contracts\PostEventsContract;
use Plugins\Posts\Application\Services\PostCategoryService;
use Plugins\Posts\Application\Services\PostInputValidator;
use Plugins\Posts\Application\Services\PostService;
use Plugins\Posts\Application\Services\PostSyndicationService;
use Plugins\Posts\Application\Services\PostViewFactory;
use Plugins\Posts\Application\Support\PostsConfig;
use Plugins\Posts\Application\Support\PreviewTokens;
use Plugins\Posts\Infrastructure\Persistence\PostCategoryRepository;
use Plugins\Posts\Infrastructure\Persistence\PostRepository;
use Plugins\Posts\Infrastructure\Persistence\PostRevisionRepository;
use Plugins\Posts\Infrastructure\Rendering\SafeBodyRenderer;

/**
 * The plugin's services for ONE database connection, built together so they
 * share one TransactionManager and one view factory (and with it one cached
 * category tree).
 *
 * Every repository and the TransactionManager wrap the SAME DatabasePort —
 * the request's, which on a Tenancy host is the tenant's. The request
 * container's own TransactionManager wraps the core connection and would
 * bracket nothing there. The CLI builds one bundle per tenant the same way.
 */
final readonly class PostServiceBundle
{
    public function __construct(
        public PostService $posts,
        public PostCategoryService $categories,
        public PostSyndicationService $syndication,
    ) {
    }

    public static function build(
        DatabasePort $db,
        ClockPort $clock,
        PostEventsContract $events,
        PostsConfig $config,
        ?BodyRendererContract $renderer = null,
    ): self {
        $transaction = new TransactionManager($db);
        $posts       = new PostRepository($db);
        $categories  = new PostCategoryRepository($db);
        $views       = new PostViewFactory($config->paths, $categories);

        return new self(
            posts: new PostService(
                posts: $posts,
                revisionStore: new PostRevisionRepository($db),
                views: $views,
                validator: new PostInputValidator(),
                renderer: $renderer ?? new SafeBodyRenderer(),
                transaction: $transaction,
                events: $events,
                clock: $clock,
                previews: new PreviewTokens($config->previewSecret),
                maxRevisions: $config->maxRevisions,
            ),
            categories: new PostCategoryService($categories, $posts, $views, $transaction, $clock),
            syndication: new PostSyndicationService($posts, $views, $config->newsKinds, $config->fullContentFeeds),
        );
    }
}
