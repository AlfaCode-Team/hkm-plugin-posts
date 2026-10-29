<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Posts\Support;

use AlfaCode\LetMigrate\Driver\SQLite\SQLiteDriver;
use AlfaCode\LetMigrate\Driver\SQLite\SQLiteGrammar;
use AlfaCode\LetMigrate\Schema\SchemaBuilder;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/**
 * A real SQLite-backed DatabasePort whose schema comes from THE PLUGIN'S OWN
 * MIGRATION, run through LetMigrate — not a hand-copied CREATE TABLE that
 * could drift from what ships. If the migration and the repository disagree
 * about a column, these tests fail.
 *
 * LetMigrate's SQLiteDriver keeps its PDO private, so the migration runs
 * against a temporary database FILE and this class opens its own connection
 * to the same file afterwards. The file is removed on destruction.
 *
 * Bool bindings become 1/0 exactly as the real adapter does
 * (`MultiDriverDatabaseAdapter::normaliseBindings()` in the Database plugin).
 */
final class SqliteDatabasePort implements DatabasePort
{
    private const string MIGRATION = __DIR__ . '/../../database/migrations/2026_10_01_000001_create_posts_tables.php';

    private \PDO $pdo;

    private string $path;

    /** Every statement run, newest last — lets a test assert what reached the database. */
    public array $statements = [];

    /** When set, the next execute() whose SQL contains this string throws. */
    public ?string $failOn = null;

    public function __construct()
    {
        $this->path = (string) tempnam(sys_get_temp_dir(), 'posts-test-');

        $migration = require self::MIGRATION;
        $migration->up(new SchemaBuilder(new SQLiteDriver($this->path), new SQLiteGrammar()));

        $this->pdo = new \PDO('sqlite:' . $this->path);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function __destruct()
    {
        unset($this->pdo);
        @unlink($this->path);
    }

    public function query(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function execute(string $sql, array $params = []): int
    {
        if ($this->failOn !== null && str_contains($sql, $this->failOn)) {
            $this->failOn = null;
            throw new \PDOException('Simulated failure.');
        }

        return $this->run($sql, $params)->rowCount();
    }

    public function upsert(string $table, array $values, array $conflictColumns, ?array $updateColumns = null): int
    {
        throw new \RuntimeException('Not used by PostRepository.');
    }

    public function lastInsertId(?string $sequence = null): string
    {
        return (string) $this->pdo->lastInsertId();
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollback(): void
    {
        $this->pdo->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /** @param array<string, mixed> $params */
    private function run(string $sql, array $params): \PDOStatement
    {
        foreach ($params as $key => $value) {
            if (is_bool($value)) {
                $params[$key] = $value ? 1 : 0;
            }
        }

        $this->statements[] = $sql;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }
}
