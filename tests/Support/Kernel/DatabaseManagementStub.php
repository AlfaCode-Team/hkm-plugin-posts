<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Posts\Support\Kernel;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;

/**
 * Stands in for the Database plugin's `database.management` domain, which
 * Posts requires. The DatabasePort itself comes from the kernel's port
 * bindings (the migrated SQLite database), exactly as in a project.
 */
final class DatabaseManagementStub implements ModuleContract
{
    public function solves(): string
    {
        return 'database.management';
    }

    public function requires(): array
    {
        return [];
    }

    public function exposes(): array
    {
        return [];
    }

    public function register(ModuleContainer $container): void
    {
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
    }
}
