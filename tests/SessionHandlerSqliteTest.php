<?php

use Bredala\Database\PDO\DB;
use Bredala\Database\SessionHandler;

/**
 * SessionHandler end to end, against SQLite.
 *
 * SessionHandler::read() is the caller that made PDO\DB::one()'s bug visible:
 * one() returned null for every query, so read() always returned '' and no
 * session was ever restored. The unit tests use a mock driver and could not see
 * it -- only a real connection can.
 */
class SessionHandlerSqliteTest extends SqliteTestCase
{
    private SessionHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new SessionHandler($this->db);
    }

    protected function migrate(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE sessions ('
                . 'id TEXT NOT NULL PRIMARY KEY,'
                . 'ts INTEGER NOT NULL DEFAULT 0,'
                . 'data TEXT NOT NULL'
                . ');'
        );
    }

    public function testWriteThenReadRestoresTheSession()
    {
        $this->handler->write('abc', 'user|i:42;');

        self::assertSame('user|i:42;', $this->handler->read('abc'));
    }

    public function testReadingAnUnknownSessionReturnsAnEmptyString()
    {
        self::assertSame('', $this->handler->read('nope'));
    }

    public function testWritingTwiceReplacesTheRow()
    {
        $this->handler->write('abc', 'first');
        $this->handler->write('abc', 'second');

        self::assertSame('second', $this->handler->read('abc'));
        self::assertCount(1, $this->rows('SELECT id FROM sessions;'));
    }

    public function testOneSessionIsNotDisturbedByAnother()
    {
        $this->handler->write('abc', 'A');
        $this->handler->write('def', 'B');

        self::assertSame('A', $this->handler->read('abc'));
        self::assertSame('B', $this->handler->read('def'));
    }

    public function testDestroy()
    {
        $this->handler->write('abc', 'A');
        $this->handler->destroy('abc');

        self::assertSame('', $this->handler->read('abc'));
    }

    public function testGcDropsTheExpiredSessionsOnly()
    {
        $this->handler->write('fresh', 'A');
        $this->pdo->exec("INSERT INTO sessions (id, ts, data) VALUES ('stale', 1, 'B');");

        $this->handler->gc(60);

        self::assertSame('A', $this->handler->read('fresh'));
        self::assertSame('', $this->handler->read('stale'));
    }

    public function testCustomColumnNames()
    {
        $this->pdo->exec('CREATE TABLE sess (sid TEXT PRIMARY KEY, seen INTEGER, payload TEXT);');

        $handler = new SessionHandler($this->db, [
            'table' => 'sess',
            'id' => 'sid',
            'time' => 'seen',
            'data' => 'payload',
        ]);

        $handler->write('abc', 'A');

        self::assertSame('A', $handler->read('abc'));
    }

    public function testASessionSurvivesTheConnectionThatWroteIt()
    {
        // The real scenario: one request writes, the next one reads on a fresh
        // connection. Needs a file database -- tests/tmp is gitignored, and the
        // file is removed in tearDown().
        $path = $this->tempPath('session.sqlite');

        $writer = $this->connect('sqlite:' . $path);
        $this->migrate($writer);
        (new SessionHandler(DB::create($writer)))->write('abc', 'survives');

        $reader = $this->connect('sqlite:' . $path);

        self::assertSame('survives', (new SessionHandler(DB::create($reader)))->read('abc'));
    }
}
