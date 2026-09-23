<?php

use Bredala\Database\Exception;
use Bredala\Database\PDO\DB;
use Bredala\Database\QB;
use Bredala\Database\Query;

/**
 * PDO\DB against a real SQLite connection.
 *
 * This class had no coverage at all, which is how one() shipped broken.
 */
class PdoDbSqliteTest extends SqliteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->seedUsers([
            [1, 'alice', 'alice@example.com', 1],
            [2, 'bob', 'bob@example.com', 1],
            [2, 'carol', null, 0],
        ]);
    }

    // -------------------------------------------------------------------------
    // one()
    // -------------------------------------------------------------------------

    public function testOneReturnsTheRowOfASingleMatch()
    {
        // one() used to gate on rowCount() === 1, which SQLite reports as 0 for
        // any SELECT, so this returned null for every query ever run.
        $row = $this->db->exec(QB::create('users')->whereEq('name', 'alice')->read())->one();

        self::assertIsArray($row);
        self::assertSame('alice', $row['name']);
        self::assertSame('alice@example.com', $row['email']);
    }

    public function testOneReturnsNullWhenNothingMatches()
    {
        self::assertNull(
            $this->db->exec(QB::create('users')->whereEq('name', 'nobody')->read())->one()
        );
    }

    public function testOneReturnsNullWhenSeveralRowsMatch()
    {
        // The documented contract is "exactly one row", not "the first row".
        self::assertNull(
            $this->db->exec(QB::create('users')->whereEq('active', 1)->read())->one()
        );
    }

    public function testOneOnASingleRowOfManyNeedsAnExplicitLimit()
    {
        $row = $this->db->exec(
            QB::create('users')->whereEq('active', 1)->orderAsc('name')->limit(1)->read()
        )->one();

        self::assertSame('alice', $row['name']);
    }

    public function testOneOnAnAggregateAlwaysHasExactlyOneRow()
    {
        $row = $this->db->exec(QB::create('users')->count())->one();

        // QB::count() aliases the result as 'sum', not 'count'.
        self::assertSame(3, (int) $row['sum']);
    }

    public function testOneReturnsNullWithoutAStatement()
    {
        self::assertNull(DB::create($this->pdo)->one());
    }

    // -------------------------------------------------------------------------
    // all() / next() / count()
    // -------------------------------------------------------------------------

    public function testAllReturnsEveryRowAsAnAssociativeArray()
    {
        $rows = $this->db->exec(QB::create('users')->select('name')->orderAsc('name')->read())->all();

        self::assertSame(['alice', 'bob', 'carol'], array_column($rows, 'name'));
    }

    public function testAllReturnsAnEmptyArrayWhenNothingMatches()
    {
        self::assertSame(
            [],
            $this->db->exec(QB::create('users')->whereEq('name', 'nobody')->read())->all()
        );
    }

    public function testNextWalksTheResultSetThenReturnsNull()
    {
        $db = $this->db->exec(QB::create('users')->orderAsc('name')->read());

        self::assertSame('alice', $db->next()['name']);
        self::assertSame('bob', $db->next()['name']);
        self::assertSame('carol', $db->next()['name']);
        self::assertNull($db->next());
    }

    public function testCountIsTheAffectedRowsOfAWrite()
    {
        $db = $this->db->exec(
            QB::create('users')->add('active', 0)->whereEq('active', 1)->update()
        );

        self::assertSame(2, $db->count());
    }

    public function testCountIsNotUsableAfterASelect()
    {
        // KNOWN LIMIT, not a bug in this package: PDO only guarantees rowCount()
        // for statements that modify rows. SQLite reports 0 for a SELECT where
        // MySQL happens to report the row count. Count in SQL with QB::count().
        $db = $this->db->exec(QB::create('users')->read());

        self::assertSame(0, $db->count());
        self::assertCount(3, $db->all());
    }

    // -------------------------------------------------------------------------
    // Writes
    // -------------------------------------------------------------------------

    public function testInsertThenGetId()
    {
        $this->db->exec(QB::create('users')->add('name', 'dave')->add('role_id', 1)->insert());

        self::assertSame(4, $this->db->getId());
        self::assertSame('dave', $this->rows('SELECT name FROM users WHERE id = 4;')[0]['name']);
    }

    public function testDelete()
    {
        $this->db->exec(QB::create('users')->whereEq('active', 0)->delete());

        self::assertCount(2, $this->rows('SELECT id FROM users;'));
    }

    public function testValuesAreBoundNotInterpolated()
    {
        $this->db->exec(QB::create('users')->add('name', "O'Brien; DROP TABLE users;--")->insert());

        self::assertSame(
            "O'Brien; DROP TABLE users;--",
            $this->rows('SELECT name FROM users ORDER BY id DESC LIMIT 1;')[0]['name']
        );
        self::assertCount(4, $this->rows('SELECT id FROM users;'));
    }

    // -------------------------------------------------------------------------
    // Transactions
    // -------------------------------------------------------------------------

    public function testRollbackDiscardsTheWrites()
    {
        $this->db->transaction();
        $this->db->exec(QB::create('users')->add('name', 'dave')->insert());
        $this->db->rollback();

        self::assertCount(3, $this->rows('SELECT id FROM users;'));
    }

    public function testCommitKeepsTheWrites()
    {
        $this->db->transaction();
        $this->db->exec(QB::create('users')->add('name', 'dave')->insert());
        $this->db->commit();

        self::assertCount(4, $this->rows('SELECT id FROM users;'));
    }

    public function testCommitWithoutATransactionThrowsADatabaseException()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(Exception::TRANSACTION);

        $this->db->commit();
    }

    // -------------------------------------------------------------------------
    // Errors and hooks
    // -------------------------------------------------------------------------

    public function testABrokenStatementThrowsADatabaseException()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(Exception::PREPARE);

        $this->db->exec(new Query('SELECT * FROM nope_this_does_not_exist;', []));
    }

    public function testAForeignKeyViolationThrowsADatabaseException()
    {
        // The PRAGMA is set by connect(); without it SQLite ignores the constraint.
        $this->expectException(Exception::class);
        $this->expectExceptionCode(Exception::EXECUTE);

        $this->db->exec(QB::create('users')->add('name', 'dave')->add('role_id', 999)->insert());
    }

    public function testHooksFireAroundAQuery()
    {
        $calls = [];

        $this->db
            ->addHook(DB::HOOK_BEFORE_QUERY, function () use (&$calls) {
                $calls[] = 'before';
            })
            ->addHook(DB::HOOK_AFTER_QUERY, function () use (&$calls) {
                $calls[] = 'after';
            })
            ->exec(QB::create('users')->read());

        self::assertSame(['before', 'after'], $calls);
    }

    public function testEscapeQuotesThroughTheDriver()
    {
        self::assertSame("'O''Brien'", $this->db->escape("O'Brien"));
    }

    public function testDisableFkCheckIsMysqlOnly()
    {
        // SET FOREIGN_KEY_CHECKS is MySQL syntax; SQLite rejects it. Use the
        // PRAGMA directly when targeting SQLite.
        $this->expectException(Exception::class);
        $this->expectExceptionCode(Exception::EXECUTE);

        $this->db->disableFkCheck();
    }
}
