<?php

declare(strict_types=1);

namespace Plugins\Posts\Infrastructure\Cli;

use Plugins\Posts\API\Contracts\PostServiceContract;

/**
 * hkm posts:rerender [--batch=100] [--tenant=a,b | --all-tenants]
 *
 * Re-renders every stored body to safe HTML. Bodies are rendered when they
 * are SAVED, so after upgrading this plugin (a new markdown feature, a
 * tightened sanitiser) run this once to bring old posts in line. It touches
 * no timestamps, creates no revisions and sends no events.
 */
final class RerenderPostsCommand extends PostsCommand
{
    protected function configure(): void
    {
        $this->name        = 'posts:rerender';
        $this->description = 'Re-render every stored post body (after a plugin upgrade)';
        $this->addOption('batch', 'b', 'Posts per batch', acceptsValue: true, default: 100);
        $this->addTenantOptions();
    }

    protected function runFor(PostServiceContract $posts, \DateTimeImmutable $now): string
    {
        $batch = max(1, (int) $this->option('batch', 100));
        $total = 0;
        $after = null;

        do {
            $result = $posts->rerender($batch, $after);
            $total += $result['processed'];
            $after  = $result['last'];
        } while ($after !== null);

        return "{$total} post(s) re-rendered";
    }
}
