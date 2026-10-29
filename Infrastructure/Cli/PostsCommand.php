<?php

declare(strict_types=1);

namespace Plugins\Posts\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\Posts\API\Contracts\PostServiceContract;

/**
 * Shared shape of the scheduled commands: run once per database.
 *
 * On a Tenancy project posts live in each TENANT database, so a run targets
 * `--all-tenants` or `--tenant=<id>[,<id>]`; without either it uses the
 * default connection — which is the whole story for a project without
 * Tenancy. One tenant failing is reported and the run continues with the
 * next; the exit code says whether any failed.
 */
abstract class PostsCommand extends AbstractCommand
{
    /**
     * @param \Closure(?string): PostServiceContract $serviceFor     The service over a tenant's database (null = default).
     * @param ?\Closure(): list<string>               $activeTenants  Null when the Tenancy plugin is not installed.
     */
    public function __construct(
        private readonly \Closure $serviceFor,
        private readonly ?\Closure $activeTenants = null,
    ) {
        parent::__construct();
    }

    /** Do this command's work against one database; return the line to print. */
    abstract protected function runFor(PostServiceContract $posts, \DateTimeImmutable $now): string;

    protected function addTenantOptions(): void
    {
        $this->addOption('tenant', 't', 'Tenant id(s), comma-separated (Tenancy projects)', acceptsValue: true);
        $this->addOption('all-tenants', 'a', 'Every active tenant (Tenancy projects)');
    }

    protected function handle(): int
    {
        $targets = [null];
        $tenant  = trim((string) $this->option('tenant', ''));

        if ($tenant !== '' || $this->hasOption('all-tenants')) {
            if ($this->activeTenants === null) {
                $this->error('--tenant / --all-tenants need the Tenancy plugin, which is not installed.');

                return self::INVALID;
            }

            try {
                $targets = $tenant !== ''
                    ? array_values(array_filter(array_map('trim', explode(',', $tenant)), static fn(string $t): bool => $t !== ''))
                    : ($this->activeTenants)();
            } catch (\Throwable $e) {
                $this->error("Could not list tenants: {$e->getMessage()}");

                return self::FAILURE;
            }

            if ($targets === []) {
                $this->info('No active tenants.');

                return self::SUCCESS;
            }
        }

        $failed = 0;
        foreach ($targets as $target) {
            $label = $target ?? 'default';

            try {
                $this->info("[{$label}] " . $this->runFor(($this->serviceFor)($target), new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
            } catch (\Throwable $e) {
                $failed++;
                $this->error("[{$label}] failed: {$e->getMessage()}");
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
