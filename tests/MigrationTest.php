<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Posts;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Plugins\Posts\Support\SqliteDatabasePort;

/**
 * The migration ships twice — central and tenant-template — so a project gets
 * the same schema with or without Tenancy. Nothing but this test stops the two
 * copies drifting apart.
 */
final class MigrationTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../database';

    public function test_central_and_tenant_migrations_are_identical(): void
    {
        $central = glob(self::ROOT . '/migrations/*.php') ?: [];
        $tenant  = glob(self::ROOT . '/tenant-template/*.php') ?: [];

        $this->assertNotEmpty($central);
        $this->assertSame(array_map('basename', $central), array_map('basename', $tenant));

        foreach ($central as $i => $file) {
            $this->assertFileEquals($file, $tenant[$i], basename($file) . ' differs between the two copies');
        }
    }

    public function test_migration_creates_every_table_and_the_kind_locale_slug_key(): void
    {
        $db = new SqliteDatabasePort();

        foreach (['posts', 'post_tags', 'post_categories', 'post_category_links', 'post_revisions', 'post_slug_redirects'] as $table) {
            $this->assertNotNull($db->queryOne("SELECT name FROM sqlite_master WHERE type = 'table' AND name = '{$table}'"), $table);
        }

        $insert = "INSERT INTO posts (public_id, kind, slug, title, created_at, updated_at)
                   VALUES (:id, 'blog', 'same', 't', '2026-01-01 00:00:00', '2026-01-01 00:00:00')";
        $db->execute($insert, ['id' => '01J00000000000000000000001']);

        $this->expectException(\PDOException::class);
        $db->execute($insert, ['id' => '01J00000000000000000000002']);
    }
}
