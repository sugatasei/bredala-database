<?php

use Bredala\Database\Column;
use PHPUnit\Framework\TestCase;

class ColumnTest extends TestCase
{
    private function column(string $name = 'c'): Column
    {
        return new Column($name);
    }

    // -------------------------------------------------------------------------
    // Defaults
    // -------------------------------------------------------------------------

    public function testAFreshColumnIsANullableVarchar255()
    {
        self::assertSame('`name` VARCHAR(255) NULL DEFAULT NULL', (string) $this->column('name'));
    }

    public function testTheNameIsBackquoted()
    {
        self::assertStringStartsWith('`my_col`', (string) $this->column('my_col'));
    }

    // -------------------------------------------------------------------------
    // Types
    // -------------------------------------------------------------------------

    /**
     * @dataProvider typeProvider
     */
    public function testTypes(string $method, array $args, string $expected)
    {
        $column = $this->column();
        $column->{$method}(...$args);

        self::assertSame("`c` {$expected}", (string) $column);
    }

    public static function typeProvider(): array
    {
        return [
            'int' => ['int', [], 'INT NULL DEFAULT NULL'],
            'bigint via prefix' => ['int', ['big'], 'BIGINT NULL DEFAULT NULL'],
            'tinyint via prefix' => ['int', ['tiny'], 'TINYINT NULL DEFAULT NULL'],
            'float' => ['float', [], 'FLOAT NULL DEFAULT NULL'],
            'decimal default precision' => ['decimal', [], 'DECIMAL(10,2) NULL DEFAULT NULL'],
            'decimal explicit' => ['decimal', [8, 4], 'DECIMAL(8,4) NULL DEFAULT NULL'],
            'char' => ['char', [10], 'CHAR(10) NULL DEFAULT NULL'],
            'varchar' => ['varchar', [50], 'VARCHAR(50) NULL DEFAULT NULL'],
            'text' => ['text', [], 'TEXT NULL DEFAULT NULL'],
            'longtext via prefix' => ['text', ['long'], 'LONGTEXT NULL DEFAULT NULL'],
            'blob' => ['blob', [], 'BLOB NULL DEFAULT NULL'],
            'timestamp' => ['timestamp', [], 'TIMESTAMP NULL DEFAULT NULL'],
            'datetime' => ['datetime', [], 'DATETIME NULL DEFAULT NULL'],
            'date' => ['date', [], 'DATE NULL DEFAULT NULL'],
            'time' => ['time', [], 'TIME NULL DEFAULT NULL'],
        ];
    }

    public function testBoolIsATinyintOneUnsignedNotNullDefaultZero()
    {
        // bool() is the one type helper that also sets unsigned, not null and a
        // default -- it is not a bare type declaration.
        self::assertSame(
            '`c` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0',
            (string) $this->column()->bool()
        );
    }

    public function testTypeAcceptsAnArbitraryTypeAndConstraints()
    {
        self::assertSame(
            "`c` ENUM('a','b') NULL DEFAULT NULL",
            (string) $this->column()->type('ENUM', "'a'", "'b'")
        );
    }

    // -------------------------------------------------------------------------
    // Modifiers
    // -------------------------------------------------------------------------

    public function testNotNullDropsTheNullDefault()
    {
        self::assertSame('`c` INT NOT NULL', (string) $this->column()->int()->notNull());
    }

    public function testUnsigned()
    {
        self::assertSame(
            '`c` INT UNSIGNED NULL DEFAULT NULL',
            (string) $this->column()->int()->unsigned()
        );
    }

    public function testUnsignedCanBeTurnedBackOff()
    {
        self::assertSame(
            '`c` INT NULL DEFAULT NULL',
            (string) $this->column()->int()->unsigned()->unsigned(false)
        );
    }

    public function testAutoIncrementImpliesUnsignedAndNotNull()
    {
        self::assertSame(
            '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT',
            (string) $this->column('id')->int()->autoIncrement()
        );
    }

    public function testDefaultValueImpliesNotNull()
    {
        self::assertSame('`c` INT NOT NULL DEFAULT 0', (string) $this->column()->int()->defaultValue(0));
    }

    public function testDefaultValueQuotesStrings()
    {
        self::assertSame(
            "`c` VARCHAR(10) NOT NULL DEFAULT 'x'",
            (string) $this->column()->varchar(10)->defaultValue('x')
        );
    }

    public function testDefaultValueCanSkipQuoting()
    {
        self::assertSame(
            '`c` INT NOT NULL DEFAULT 1+1',
            (string) $this->column()->int()->defaultValue('1+1', false)
        );
    }

    public function testAFalsyStringDefaultIsQuoted()
    {
        // Quoting goes through FB::quote(), which used to return '' for any falsy
        // value, so DEFAULT '0' and DEFAULT '' emitted a bare 'DEFAULT'.
        self::assertSame("`c` INT NOT NULL DEFAULT '0'", (string) $this->column()->int()->defaultValue('0'));
        self::assertSame(
            "`c` VARCHAR(10) NOT NULL DEFAULT ''",
            (string) $this->column()->varchar(10)->defaultValue('')
        );
    }

    /**
     * @dataProvider booleanDefaultProvider
     */
    public function testABooleanDefaultBecomesOneOrZero(bool $value, string $expected)
    {
        // A bool does not take the string branch, so it never reaches FB::quote();
        // false used to interpolate to nothing and emit a bare DEFAULT.
        self::assertSame(
            "`c` INT NOT NULL DEFAULT {$expected}",
            (string) $this->column()->int()->defaultValue($value)
        );
    }

    public static function booleanDefaultProvider(): array
    {
        return [
            'false' => [false, '0'],
            'true' => [true, '1'],
        ];
    }

    public function testABooleanDefaultMatchesWhatBoolEmits()
    {
        self::assertSame(
            (string) $this->column()->bool(false),
            (string) $this->column()->bool()->defaultValue(false)
        );
    }

    public function testAnIntegerZeroDefaultIsFine()
    {
        // Integers do not go through quote(), so 0 survives.
        self::assertSame('`c` INT NOT NULL DEFAULT 0', (string) $this->column()->int()->defaultValue(0));
    }

    public function testDefaultTimestamp()
    {
        self::assertSame(
            '`t` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            (string) $this->column('t')->timestamp()->defaultTimestamp()
        );
    }

    public function testDefaultTimestampOnUpdate()
    {
        self::assertSame(
            '`t` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP  ON UPDATE CURRENT_TIMESTAMP',
            (string) $this->column('t')->timestamp()->defaultTimestamp(true)
        );
    }

    public function testComment()
    {
        self::assertSame(
            "`c` INT NULL DEFAULT NULL COMMENT 'hi'",
            (string) $this->column()->int()->comment('hi')
        );
    }

    public function testFirst()
    {
        self::assertSame('`c` INT NULL DEFAULT NULL FIRST', (string) $this->column()->int()->first());
    }

    public function testAfter()
    {
        self::assertSame(
            '`c` INT NULL DEFAULT NULL AFTER `id`',
            (string) $this->column()->int()->after('id')
        );
    }

    public function testFirstWinsOverAfterRegardlessOfCallOrder()
    {
        // __toString() checks 'first' before 'after', so once first() is set,
        // after() has no effect -- call order does not decide.
        self::assertSame(
            '`c` INT NULL DEFAULT NULL FIRST',
            (string) $this->column()->int()->first()->after('id')
        );
        self::assertSame(
            '`c` INT NULL DEFAULT NULL FIRST',
            (string) $this->column()->int()->after('id')->first()
        );
    }

    // -------------------------------------------------------------------------
    // Fluency and ordering
    // -------------------------------------------------------------------------

    public function testEveryMethodIsFluent()
    {
        $column = $this->column();

        self::assertSame($column, $column->int());
        self::assertSame($column, $column->unsigned());
        self::assertSame($column, $column->notNull());
        self::assertSame($column, $column->comment('x'));
    }

    public function testTheOutputOrderIsFixedRegardlessOfCallOrder()
    {
        $a = $this->column()->int()->comment('hi')->unsigned()->notNull();
        $b = $this->column()->int()->unsigned()->notNull()->comment('hi');

        self::assertSame((string) $a, (string) $b);
        self::assertSame("`c` INT UNSIGNED NOT NULL COMMENT 'hi'", (string) $a);
    }

    public function testRedeclaringTheTypeReplacesIt()
    {
        self::assertSame(
            '`c` TEXT NULL DEFAULT NULL',
            (string) $this->column()->int()->text()
        );
    }

    public function testACompleteColumn()
    {
        self::assertSame(
            "`price` DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'in cents' AFTER `id`",
            (string) $this->column('price')
                ->decimal()
                ->unsigned()
                ->defaultValue(0)
                ->comment('in cents')
                ->after('id')
        );
    }
}
