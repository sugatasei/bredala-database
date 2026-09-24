<?php

use Bredala\Database\DBInterface;
use Bredala\Database\QueryInterface;
use Bredala\Database\SessionHandler;
use PHPUnit\Framework\TestCase;

class SessionHandlerTest extends TestCase
{
    /**
     * @var QueryInterface[]
     */
    private array $queries = [];

    /**
     * A DBInterface mock that records every Query handed to exec(), so the
     * generated SQL can be asserted without a database.
     */
    private function driver(?array $row = null): DBInterface
    {
        $this->queries = [];

        $driver = $this->createMock(DBInterface::class);
        $driver->method('exec')->willReturnCallback(function (QueryInterface $query) use ($driver) {
            $this->queries[] = $query;
            return $driver;
        });
        $driver->method('one')->willReturn($row);

        return $driver;
    }

    private function lastStatement(): string
    {
        return end($this->queries)->getStatement();
    }

    private function lastData(): array
    {
        return end($this->queries)->getData();
    }

    public function testImplementsTheSessionHandlerInterface()
    {
        self::assertInstanceOf(\SessionHandlerInterface::class, new SessionHandler($this->driver()));
    }

    // -------------------------------------------------------------------------
    // read
    // -------------------------------------------------------------------------

    public function testReadQueriesTheConfiguredTable()
    {
        (new SessionHandler($this->driver()))->read('abc');

        self::assertSame("SELECT\n\t*\nFROM sessions\nWHERE\n\tid = ?;", $this->lastStatement());
        self::assertSame(['abc'], $this->lastData());
    }

    public function testReadReturnsTheDataColumn()
    {
        $handler = new SessionHandler($this->driver(['id' => 'abc', 'data' => 'payload']));

        self::assertSame('payload', $handler->read('abc'));
    }

    public function testReadReturnsAnEmptyStringForAnUnknownSession()
    {
        self::assertSame('', (new SessionHandler($this->driver()))->read('abc'));
    }

    // -------------------------------------------------------------------------
    // write
    // -------------------------------------------------------------------------

    public function testWriteReplacesIntoTheConfiguredTable()
    {
        $handler = new SessionHandler($this->driver());

        self::assertTrue($handler->write('abc', 'payload'));
        self::assertSame(
            "REPLACE INTO\n\tsessions(\n\t\tid,\n\t\tts,\n\t\tdata\n\t)"
                . "\nVALUES \t(\n\t\t?,\n\t\t?,\n\t\t?\n\t);",
            $this->lastStatement()
        );
    }

    public function testWriteBindsIdTimestampAndData()
    {
        (new SessionHandler($this->driver()))->write('abc', 'payload');

        $data = $this->lastData();

        self::assertSame('abc', $data[0]);
        self::assertIsInt($data[1]);
        self::assertSame('payload', $data[2]);
    }

    // -------------------------------------------------------------------------
    // destroy / gc
    // -------------------------------------------------------------------------

    public function testDestroyDeletesOneSession()
    {
        $handler = new SessionHandler($this->driver());

        self::assertTrue($handler->destroy('abc'));
        self::assertSame("DELETE  FROM\n\tsessions\nWHERE\n\tid = ?;", $this->lastStatement());
        self::assertSame(['abc'], $this->lastData());
    }

    public function testGcDeletesExpiredSessions()
    {
        $driver = $this->driver();
        $driver->method('count')->willReturn(3);
        $handler = new SessionHandler($driver);

        self::assertSame(3, $handler->gc(1440));
        self::assertSame("DELETE  FROM\n\tsessions\nWHERE\n\tts < ?;", $this->lastStatement());
        self::assertLessThanOrEqual(time() - 1440, $this->lastData()[0]);
    }

    // -------------------------------------------------------------------------
    // Options
    // -------------------------------------------------------------------------

    public function testTableAndColumnNamesAreConfigurable()
    {
        $handler = new SessionHandler($this->driver(), [
            'table' => 'php_sessions',
            'id' => 'sid',
            'time' => 'updated_at',
            'data' => 'payload',
        ]);

        $handler->read('abc');
        self::assertSame("SELECT\n\t*\nFROM php_sessions\nWHERE\n\tsid = ?;", $this->lastStatement());

        $handler->gc(60);
        self::assertStringContainsString('updated_at < ?', $this->lastStatement());
    }

    public function testTheReadColumnFollowsTheDataOption()
    {
        $handler = new SessionHandler(
            $this->driver(['payload' => 'custom']),
            ['data' => 'payload']
        );

        self::assertSame('custom', $handler->read('abc'));
    }

    // -------------------------------------------------------------------------
    // open / close
    // -------------------------------------------------------------------------

    public function testOpenAndCloseSucceedWithADriver()
    {
        $handler = new SessionHandler($this->driver());

        self::assertTrue($handler->open('/tmp', 'PHPSESSID'));
        self::assertTrue($handler->close());
    }
}
