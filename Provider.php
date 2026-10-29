<?php

declare(strict_types=1);

namespace Plugins\Posts;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SystemClock;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Posts\API\Contracts\PostCategoryServiceContract;
use Plugins\Posts\API\Contracts\PostServiceContract;
use Plugins\Posts\API\Contracts\PostSyndicationContract;
use Plugins\Posts\Application\Services\PostEditorialService;
use Plugins\Posts\Application\Services\PostViewCounter;
use Plugins\Posts\Application\Support\PostsConfig;
use Plugins\Posts\Infrastructure\Cli\EmptyPostTrashCommand;
use Plugins\Posts\Infrastructure\Cli\PublishDuePostsCommand;
use Plugins\Posts\Infrastructure\Cli\RerenderPostsCommand;
use Plugins\Posts\Infrastructure\Events\EventBusPostEvents;
use Plugins\Posts\Infrastructure\Http\HttpContext;
use Plugins\Posts\Infrastructure\Http\Stages\PostsConditionalGetStage;
use Plugins\Posts\Infrastructure\Http\Stages\PostsRateLimitStage;
use Plugins\Posts\Infrastructure\Http\Stages\RequirePostsEditorStage;
use Plugins\Posts\Infrastructure\Rendering\SafeBodyRenderer;
use Plugins\Posts\Infrastructure\Wiring\PostServiceBundle;

/**
 * Provider — wires the 'post.publishing' domain: blog posts, articles and
 * press releases, with categories, revisions, feeds and an admin API.
 *
 * Everything is request-scoped and built over the REQUEST's DatabasePort (the
 * tenant's under Tenancy): PostServiceBundle builds the repositories AND the
 * TransactionManager over that one connection, so a post and everything that
 * hangs off it commit together on the database they were written to.
 *
 * The plugin registers its own route filters — `posts.editor` (admin door)
 * and `posts.rate_limit` — so its routes need neither SecurityFilters nor any
 * project wiring. A project drops any route it does not want with
 * `routePolicy.disable` in proj.json, or the whole set with
 * `"disable": ["post.publishing"]` and keeps only the contracts.
 */
final class Provider implements ModuleContract
{
    public const string DOMAIN = 'post.publishing';

    public function solves(): string
    {
        return self::DOMAIN;
    }

    /** @return list<string> */
    public function requires(): array
    {
        return ['database.management'];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [PostServiceContract::class, PostCategoryServiceContract::class, PostSyndicationContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        $container->bindInternal(PostsConfig::class, static fn(): PostsConfig => PostsConfig::fromEnvironment());

        $container->bindInternal(PostServiceBundle::class, static fn(ModuleContainer $c): PostServiceBundle => PostServiceBundle::build(
            db: $c->make(DatabasePort::class),
            clock: self::clock($c),
            // A view of the bus over THIS container, so listeners can depend
            // on module bindings (see EventBus::forContainer()).
            events: new EventBusPostEvents($c->make(EventBus::class)->forContainer($c)),
            config: $c->make(PostsConfig::class),
        ));

        $container->singleton(PostServiceContract::class, static fn(ModuleContainer $c) => $c->make(PostServiceBundle::class)->posts);
        $container->singleton(PostCategoryServiceContract::class, static fn(ModuleContainer $c) => $c->make(PostServiceBundle::class)->categories);
        $container->singleton(PostSyndicationContract::class, static fn(ModuleContainer $c) => $c->make(PostServiceBundle::class)->syndication);

        $container->bindInternal(PostEditorialService::class, static function (ModuleContainer $c): PostEditorialService {
            $config = $c->make(PostsConfig::class);

            return new PostEditorialService(
                posts: $c->make(PostServiceContract::class),
                categories: $c->make(PostCategoryServiceContract::class),
                identity: $c->has(Identity::class) ? $c->make(Identity::class) : Identity::guest(),
                writePermission: $config->writePermission,
                publishPermission: $config->publishPermission,
                managePermission: $config->managePermission,
                renderer: new SafeBodyRenderer(),
            );
        });

        $container->bindInternal(PostViewCounter::class, static fn(ModuleContainer $c): PostViewCounter => new PostViewCounter(
            posts: $c->make(PostServiceContract::class),
            cache: $c->has(CachePort::class) ? $c->make(CachePort::class) : null,
            dedupeSeconds: $c->make(PostsConfig::class)->viewDedupeSeconds,
        ));

        $container->bindInternal(HttpContext::class, static fn(ModuleContainer $c): HttpContext =>
            new HttpContext(self::clock($c), $c->make(PostsConfig::class)));
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        $http->filter(RequirePostsEditorStage::ALIAS, RequirePostsEditorStage::class);
        $http->filter(PostsRateLimitStage::ALIAS, PostsRateLimitStage::class);
        $http->filter(PostsConditionalGetStage::ALIAS, PostsConditionalGetStage::class);

        // `hkm posts:*`. CLI commands are built from the CoreContainer, which
        // holds none of the module bindings, so — like Payment and Tenancy —
        // build a scoped container here. Deferred: HTTP never pays for it.
        $cli->defer(static function (CliPipeline $cli) use ($events): void {
            $c = new ModuleContainer($cli->container());

            self::registerIfInstalled($c, 'Plugins\\Database\\Provider');

            $tenancy = class_exists('Plugins\\Tenancy\\Provider')
                && interface_exists('Plugins\\Tenancy\\API\\Contracts\\TenantConnectionResolverContract');
            $tenancyBlocked = $tenancy && !$cli->container()->has(CachePort::class)
                ? 'the Tenancy plugin needs a CachePort binding (withPorts) to resolve tenant databases from the CLI'
                : null;
            if ($tenancy && $tenancyBlocked === null) {
                self::registerIfInstalled($c, 'Plugins\\Crypto\\Provider');
                self::registerIfInstalled($c, 'Plugins\\Tenancy\\Provider');
            }

            $c->setScope(self::DOMAIN);
            $bus = new EventBusPostEvents($events->forContainer($c));

            $serviceFor = static function (?string $tenant) use ($c, $bus, $tenancyBlocked): PostServiceContract {
                if ($tenant !== null && $tenancyBlocked !== null) {
                    throw new \RuntimeException($tenancyBlocked);
                }

                $db = $tenant === null
                    ? $c->make(DatabasePort::class)
                    : $c->make('Plugins\\Tenancy\\API\\Contracts\\TenantConnectionResolverContract')->for($tenant);

                return PostServiceBundle::build($db, self::clock($c), $bus, PostsConfig::fromEnvironment())->posts;
            };

            $activeTenants = $tenancy ? static function () use ($c, $tenancyBlocked): array {
                if ($tenancyBlocked !== null) {
                    throw new \RuntimeException($tenancyBlocked);
                }
                $registry = $c->make('Plugins\\Tenancy\\API\\Contracts\\TenantRegistryContract');
                $ids      = [];
                for ($offset = 0; ; $offset += 200) {
                    $page = $registry->listByStatus(1, 200, $offset); // 1 = TenantStatus::Active
                    foreach ($page as $tenant) {
                        $ids[] = (string) $tenant->tenantId;
                    }
                    if (\count($page) < 200) {
                        return $ids;
                    }
                }
            } : null;

            $cli->command(new PublishDuePostsCommand($serviceFor, $activeTenants));
            $cli->command(new EmptyPostTrashCommand($serviceFor, $activeTenants));
            $cli->command(new RerenderPostsCommand($serviceFor, $activeTenants));
        });
    }

    private static function clock(ModuleContainer $c): ClockPort
    {
        // ClockPort has no default binding; the kernel itself falls back to
        // SystemClock the same way.
        return $c->has(ClockPort::class) ? $c->make(ClockPort::class) : new SystemClock();
    }

    private static function registerIfInstalled(ModuleContainer $c, string $providerClass): void
    {
        if (!class_exists($providerClass)) {
            return;
        }
        /** @var ModuleContract $provider */
        $provider = new $providerClass();
        $c->setScope($provider->solves());
        $provider->register($c);
    }
}
