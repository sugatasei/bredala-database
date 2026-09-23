<?php

use Bredala\Database\PDO\DB;
use Bredala\Database\PDO\Factory;
use PHPUnit\Framework\TestCase;

/**
 * Base class for the tests that run the generated SQL through a real engine.
 *
 * SQLite is the only driver the package can be tested against without a server,
 * and it is also the one that exposed PDO\DB::one(): its rowCount() reports 0
 * for a SELECT, where MySQL happens to report the number of rows. A string
 * assertion on the generated SQL cannot catch that class of bug.
 *
 * Databases are in memory, so a run leaves nothing on disk. A test that needs
 * two connections onto the same data takes a file from tempPath() instead, and
 * it is deleted in tearDown().
 */
abstract class SqliteTestCase extends TestCase
{
    protected PDO $pdo;
    protected DB $db;

    /**
     * @var string[] files handed out by tempPath()
     */
    private array $temp_files = [];

    protected function setUp(): void
    {
        $this->pdo = $this->connect();
        $this->db = DB::create($this->pdo);

        $this->migrate($this->pdo);
    }

    protected function tearDown(): void
    {
        foreach ($this->temp_files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->temp_files = [];
    }

    /**
     * A connection built the way the package builds one, so the tests exercise
     * Factory's defaults -- exceptions on, no emulated prepares, no stringified
     * fetches -- rather than a hand-rolled PDO.
     */
    protected function connect(string $dsn = 'sqlite::memory:'): PDO
    {
        $pdo = Factory::create($dsn);
        $pdo->exec('PRAGMA foreign_keys = ON;');

        return $pdo;
    }

    /**
     * Path to a scratch database file, registered for deletion. tests/tmp is
     * gitignored.
     */
    protected function tempPath(string $name): string
    {
        $dir = __DIR__ . '/../tmp';

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $file = $dir . '/' . $name;
        $this->temp_files[] = $file;

        if (is_file($file)) {
            unlink($file);
        }

        return $file;
    }

    /**
     * Schema shared by the integration tests.
     */
    protected function migrate(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT NOT NULL);');
        $pdo->exec(
            'CREATE TABLE users ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
                . 'role_id INTEGER NULL,'
                . 'name TEXT NOT NULL,'
                . 'email TEXT NULL,'
                . 'active INTEGER NOT NULL DEFAULT 1,'
                . 'CONSTRAINT fk_role_id FOREIGN KEY (role_id) REFERENCES roles(id)'
                . ');'
        );
    }

    /**
     * @param array<int, array{0: int, 1: string}> $roles
     */
    protected function seedRoles(array $roles = [[1, 'admin'], [2, 'member']]): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO roles (id, name) VALUES (?, ?);');

        foreach ($roles as $role) {
            $stmt->execute($role);
        }
    }

    /**
     * @param array<int, array{0: ?int, 1: string, 2: ?string, 3: int}> $users
     */
    protected function seedUsers(array $users): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (role_id, name, email, active) VALUES (?, ?, ?, ?);'
        );

        foreach ($users as $user) {
            $stmt->execute($user);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function rows(string $sql, array $data = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data ?: null);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
