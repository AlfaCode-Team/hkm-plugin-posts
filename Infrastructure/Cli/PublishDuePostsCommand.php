<?php

declare(strict_types=1);

namespace Plugins\Posts\Infrastructure\Cli;

use Plugins\Posts\API\Contracts\PostServiceContract;

/**
 * hkm posts:publish-due [--limit=100] [--tenant=a,b | --all-tenants]
 *
 * Sends `post.published` for scheduled posts whose time has come. Readers see
 * a scheduled post the moment its time passes whether or not this runs — the
 * public queries compare against the clock — so this command only drives the
 * SIDE EFFECTS (sitemap ping, newsletter, cache purge). Run it every minute
 * from cron; concurrent runs are safe, each post is claimed before it is
 * announced.
 */
final class PublishDuePostsCommand extends PostsCommand
{
    protected function configure(): void
    {
        $this->name        = 'posts:publish-due';
        $this->description = 'Announce scheduled posts whose publication time has arrived';
        $this->addOption('limit', 'l', 'Max posts per database per run', acceptsValue: true, default: 100);
        $this->addTenantOptions();
    }

    protected function runFor(PostServiceContract $posts, \DateTimeImmutable $now): string
    {
        $announced = $posts->publishDue($now, max(1, (int) $this->option('limit', 100)));

        return "{$announced} post(s) announced";
    }
}
