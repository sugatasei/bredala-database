<?php

use Bredala\Database\SQLiteBuilder;
use PHPUnit\Framework\TestCase;

class SQLiteBuilderTest extends TestCase
{
    public function testCreateIsEquivalentToNew()
    {
        self::assertSame(
            (new SQLiteBuilder('t'))->col('a', 'TEXT')->add(),
            SQLiteBuilder::create('t')->col('a', 'TEXT')->add()
        );
    }

    public function testEveryBuilderMethodIsFluent()
    {
        $builder = SQLiteBuilder::create('t');

        self::assertSame($builder, $builder->col('a', 'TEXT'));
        self::assertSame($builder, $builder->pk('a'));
        self::assertSame($builder, $builder->fk('a', 'o', 'id'));
    }

    public function testAddBuildsACreateTable()
    {
        self::assertSame(
            "CREATE TABLE t (\n\tid INTEGER PRIMARY KEY,\n\tn TEXT\n);",
            SQLiteBuilder::create('t')->col('id', 'INTEGER', 'PRIMARY KEY')->col('n', 'TEXT')->add()
        );
    }

    public function testColumnOptionsAreOptional()
    {
        self::assertSame(
            "CREATE TABLE t (\n\ta TEXT\n);",
            SQLiteBuilder::create('t')->col('a', 'TEXT')->add()
        );
    }

    public function testPkAddsAConstraint()
    {
        self::assertSame(
            "CREATE TABLE t (\n\tid INTEGER,\n\tCONSTRAINT pk PRIMARY KEY (id)\n);",
            SQLiteBuilder::create('t')->col('id', 'INTEGER')->pk('id')->add()
        );
    }

    public function testPkAcceptsSeveralColumns()
    {
        self::assertStringContainsString(
            'CONSTRAINT pk PRIMARY KEY (a, b)',
            SQLiteBuilder::create('t')->col('a', 'INTEGER')->col('b', 'INTEGER')->pk('a', 'b')->add()
        );
    }

    public function testFkAddsAConstraint()
    {
        self::assertSame(
            "CREATE TABLE t (\n\ta INTEGER,\n\tCONSTRAINT fk_a FOREIGN KEY (a) REFERENCES o(id)\n);",
            SQLiteBuilder::create('t')->col('a', 'INTEGER')->fk('a', 'o', 'id')->add()
        );
    }

    public function testConstraintLinesAreCommaSeparated()
    {
        // add() used to join the constraint lines with "\n\t" instead of ",\n\t",
        // so two constraints produced SQL that SQLite rejects. Invisible with a
        // single constraint, which is why it survived.
        $sql = SQLiteBuilder::create('t')
            ->col('id', 'INTEGER')
            ->col('a', 'INTEGER')
            ->pk('id')
            ->fk('a', 'o', 'id')
            ->add();

        self::assertSame(
            "CREATE TABLE t (\n\tid INTEGER,\n\ta INTEGER,\n"
                . "\tCONSTRAINT pk PRIMARY KEY (id),\n"
                . "\tCONSTRAINT fk_a FOREIGN KEY (a) REFERENCES o(id)\n);",
            $sql
        );
    }

    public function testTwoForeignKeysAreCommaSeparated()
    {
        $sql = SQLiteBuilder::create('t')
            ->col('a', 'INTEGER')
            ->col('b', 'INTEGER')
            ->fk('a', 'o', 'id')
            ->fk('b', 'p', 'id')
            ->add();

        self::assertStringContainsString("REFERENCES o(id),\n\tCONSTRAINT fk_b", $sql);
    }

    public function testTheGeneratedCreateTableIsAcceptedBySQLite()
    {
        $sql = SQLiteBuilder::create('t')
            ->col('id', 'INTEGER')
            ->col('a', 'INTEGER')
            ->pk('id')
            ->fk('a', 'o', 'id')
            ->add();

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE o (id INTEGER PRIMARY KEY);');

        self::assertNotFalse($pdo->exec($sql));
    }

    public function testASingleConstraintIsStillValid()
    {
        // With exactly one constraint the missing separator does not show, because
        // the columns themselves are comma-joined correctly.
        self::assertStringContainsString(
            "id INTEGER,\n\tCONSTRAINT pk",
            SQLiteBuilder::create('t')->col('id', 'INTEGER')->pk('id')->add()
        );
    }

    public function testRename()
    {
        self::assertSame('ALTER TABLE t RENAME TO u;', SQLiteBuilder::create('t')->rename('u'));
    }

    public function testDelHasNoTrailingSemicolon()
    {
        self::assertSame('DROP TABLE IF EXISTS t', SQLiteBuilder::create('t')->del());
    }

    public function testIndex()
    {
        self::assertSame('CREATE INDEX idx_n ON t (n);', SQLiteBuilder::create('t')->index('n'));
    }

    public function testUnique()
    {
        self::assertSame('CREATE UNIQUE INDEX unq_n ON t (n);', SQLiteBuilder::create('t')->unique('n'));
    }

    public function testIndexNamesAreNotTablePrefixed()
    {
        // Unlike FB, the index name carries no table prefix, so two tables with a
        // column of the same name collide (SQLite index names are database-wide).
        self::assertSame(
            SQLiteBuilder::create('a')->index('name'),
            str_replace(' ON b ', ' ON a ', SQLiteBuilder::create('b')->index('name'))
        );
    }

    public function testTerminalsDoNotResetTheBuilder()
    {
        // Unlike FB, the state survives, so a terminal can be called twice.
        $builder = SQLiteBuilder::create('t')->col('a', 'TEXT');

        self::assertSame($builder->add(), $builder->add());
    }
}
