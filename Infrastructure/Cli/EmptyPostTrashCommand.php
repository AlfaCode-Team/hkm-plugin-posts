<?php

declare(strict_types=1);

namespace Plugins\Posts\Infrastructure\Cli;

use Plugins\Posts\API\Contracts\PostServiceContract;

/**
 * hkm posts:empty-trash [--days=30] [--limit=500] [--tenant=a,b | --all-tenants]
 *
 * Permanently deletes posts that have sat in the trash longer than `--days`,
 * with their revisions, tags and redirects. Run it daily.
 */
final class EmptyPostTrashCommand extends PostsCommand
{
    protected function configure(): void
    {
        $this->name        = 'posts:empty-trash';
        $this->description = 'Permanently delete posts trashed more than --days ago';
        $this->addOption('days', 'd', 'Keep trashed posts this many days', acceptsValue: true, default: 30);
        $this->addOption('limit', 'l', 'Max posts per database per run', acceptsValue: true, default: 500);
        $this->addTenantOptions();
    }

    protected function runFor(PostServiceContract $posts, \DateTimeImmutable $now): string
    {
        $days   = max(1, (int) $this->option('days', 30));
        $purged = $posts->emptyTrash($now->modify("-{$days} days"), max(1, (int) $this->option('limit', 500)));

        return "{$purged} post(s) purged from the trash";
    }
}
