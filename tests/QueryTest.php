<?php

use Bredala\Database\Query;
use Bredala\Database\QueryInterface;
use PHPUnit\Framework\TestCase;

class QueryTest extends TestCase
{
    public function testImplementsTheInterface()
    {
        self::assertInstanceOf(QueryInterface::class, new Query());
    }

    public function testDefaultsToASemicolonOnly()
    {
        $query = new Query();

        self::assertSame(';', $query->getStatement());
        self::assertSame([], $query->getData());
    }

    public function testConstructorArguments()
    {
        $query = new Query('SELECT 1', [1]);

        self::assertSame('SELECT 1;', $query->getStatement());
        self::assertSame([1], $query->getData());
    }

    public function testSettersAreFluent()
    {
        $query = new Query();

        self::assertSame($query, $query->setStatement('SELECT 1'));
        self::assertSame($query, $query->setData([]));
    }

    /**
     * @dataProvider statementProvider
     */
    public function testStatementIsNormalizedToExactlyOneTrailingSemicolon(string $input)
    {
        self::assertSame('SELECT 1;', (new Query($input))->getStatement());
    }

    public static function statementProvider(): array
    {
        return [
            'none' => ['SELECT 1'],
            'one' => ['SELECT 1;'],
            'several' => ['SELECT 1;;;'],
            'padded' => ['  SELECT 1  '],
            'padded with semicolon' => ['  SELECT 1;  '],
        ];
    }

    public function testSetDataReplaces()
    {
        $query = new Query('SELECT ?', [1]);
        $query->setData([2]);

        self::assertSame([2], $query->getData());
    }

    public function testSetDataWithNoArgumentClears()
    {
        $query = new Query('SELECT ?', [1]);
        $query->setData();

        self::assertSame([], $query->getData());
    }

    // -------------------------------------------------------------------------
    // __toString
    // -------------------------------------------------------------------------

    public function testToStringInterpolatesEachPlaceholderInOrder()
    {
        $query = new Query('SELECT * FROM t WHERE a = ? AND b = ?', [1, 2]);

        self::assertSame('SELECT * FROM t WHERE a = 1 AND b = 2;', (string) $query);
    }

    public function testToStringQuotesStrings()
    {
        $query = new Query('SELECT * FROM t WHERE a = ?', ['x']);

        self::assertSame("SELECT * FROM t WHERE a = 'x';", (string) $query);
    }

    public function testToStringDoesNotEscapeQuotes()
    {
        // Debug rendering only: the value is wrapped in quotes with no escaping,
        // so the result is not safe to execute. Never build SQL this way.
        $query = new Query('SELECT ?', ["O'Brien"]);

        self::assertSame("SELECT 'O'Brien';", (string) $query);
    }

    public function testToStringStopsWhenPlaceholdersRunOut()
    {
        $query = new Query('SELECT ?', [1, 2, 3]);

        self::assertSame('SELECT 1;', (string) $query);
    }

    public function testToStringLeavesExtraPlaceholdersAlone()
    {
        $query = new Query('SELECT ?, ?', [1]);

        self::assertSame('SELECT 1, ?;', (string) $query);
    }

    public function testToStringWithoutData()
    {
        self::assertSame('SELECT 1;', (string) new Query('SELECT 1'));
    }

    public function testToStringRendersNullAsEmpty()
    {
        // null is not a string, so it is interpolated unquoted as ''.
        self::assertSame('SELECT ;', (string) new Query('SELECT ?', [null]));
    }
}
