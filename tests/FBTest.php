<?php

use Bredala\Database\Column;
use Bredala\Database\Exception;
use Bredala\Database\FB;
use PHPUnit\Framework\TestCase;

class FBTest extends TestCase
{
    private function fb(): FB
    {
        return FB::create();
    }

    // -------------------------------------------------------------------------
    // Schema
    // -------------------------------------------------------------------------

    public function testCreateSchema()
    {
        self::assertSame(
            'CREATE SCHEMA IF NOT EXISTS `app` DEFAULT CHARACTER SET utf8mb4 DEFAULT COLLATE utf8mb4_general_ci;',
            $this->fb()->createSchema('app')
        );
    }

    public function testCreateSchemaWithAnExplicitCharset()
    {
        self::assertStringContainsString(
            'DEFAULT CHARACTER SET latin1 DEFAULT COLLATE latin1_bin',
            $this->fb()->createSchema('app', 'latin1', 'latin1_bin')
        );
    }

    public function testDropSchemaEmitsDropDatabase()
    {
        // Note the asymmetry: createSchema() says SCHEMA, dropSchema() says DATABASE
        // (they are synonyms in MySQL).
        self::assertSame('DROP DATABASE IF EXISTS `app`;', $this->fb()->dropSchema('app'));
    }

    // -------------------------------------------------------------------------
    // Tables
    // -------------------------------------------------------------------------

    public function testCreateTable()
    {
        $sql = $this->fb()
            ->addColumn('id', fn(Column $c) => $c->int()->autoIncrement())
            ->addColumn('name', fn(Column $c) => $c->varchar(100))
            ->addPrimary('id')
            ->createTable('users');

        self::assertSame(
            "CREATE TABLE IF NOT EXISTS `users` (\n"
                . "\t`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
                . "\t`name` VARCHAR(100) NULL DEFAULT NULL,\n"
                . "\tPRIMARY KEY (`id`)\n"
                . ");",
            $sql
        );
    }

    public function testCreateTableWithAComment()
    {
        self::assertStringContainsString(
            "COMMENT 'Les utilisateurs'",
            $this->fb()->addColumn('id', fn(Column $c) => $c->int())->createTable('users', 'Les utilisateurs')
        );
    }

    public function testCreateTableAlwaysUsesIfNotExists()
    {
        self::assertStringStartsWith(
            'CREATE TABLE IF NOT EXISTS',
            $this->fb()->createTable('users')
        );
    }

    public function testCreateTableWithNoColumnsProducesInvalidSql()
    {
        // No guard: an empty definition still emits a CREATE TABLE with an empty
        // body, which MySQL rejects.
        self::assertSame("CREATE TABLE IF NOT EXISTS `empty` (\n\t\n);", $this->fb()->createTable('empty'));
    }

    public function testDropTable()
    {
        self::assertSame('DROP TABLE IF EXISTS `users`;', $this->fb()->dropTable('users'));
    }

    public function testRenameTable()
    {
        self::assertSame('ALTER TABLE `a` RENAME TO `b`;', $this->fb()->renameTable('a', 'b'));
    }

    public function testADottedNameIsSplitIntoSchemaAndTable()
    {
        self::assertSame('DROP TABLE IF EXISTS `app`.`users`;', $this->fb()->dropTable('app.users'));
    }

    // -------------------------------------------------------------------------
    // Columns
    // -------------------------------------------------------------------------

    public function testAddColumn()
    {
        self::assertSame(
            "ALTER TABLE `users` \n\tADD COLUMN `age` INT NULL DEFAULT NULL;",
            $this->fb()->addColumn('age', fn(Column $c) => $c->int())->alterTable('users')
        );
    }

    public function testAddColumnWithoutACallbackUsesTheDefaultVarchar()
    {
        self::assertStringContainsString(
            '`age` VARCHAR(255) NULL DEFAULT NULL',
            $this->fb()->addColumn('age')->alterTable('users')
        );
    }

    public function testDropColumn()
    {
        self::assertSame(
            "ALTER TABLE `users` \n\tDROP COLUMN `age`;",
            $this->fb()->dropColumn('age')->alterTable('users')
        );
    }

    public function testChangeColumnWithARename()
    {
        self::assertSame(
            "ALTER TABLE `users` \n\tCHANGE COLUMN `old` `new` INT NULL DEFAULT NULL;",
            $this->fb()->changeColumn('old', 'new', fn(Column $c) => $c->int())->alterTable('users')
        );
    }

    public function testChangeColumnWithoutARenameKeepsTheName()
    {
        self::assertStringContainsString(
            'CHANGE COLUMN `age` `age` INT',
            $this->fb()->changeColumn('age', null, fn(Column $c) => $c->int())->alterTable('users')
        );
    }

    public function testSeveralOperationsAreCommaSeparatedWithDropsFirst()
    {
        // The emission order is fixed by the builder (drops before adds), not by
        // the order you called the methods in.
        self::assertSame(
            "ALTER TABLE `users` \n\tDROP COLUMN `b`,\n\tADD COLUMN `a` INT NULL DEFAULT NULL;",
            $this->fb()
                ->addColumn('a', fn(Column $c) => $c->int())
                ->dropColumn('b')
                ->alterTable('users')
        );
    }

    // -------------------------------------------------------------------------
    // Keys
    // -------------------------------------------------------------------------

    public function testAddPrimary()
    {
        self::assertStringContainsString(
            'PRIMARY KEY (`id`)',
            $this->fb()->addColumn('id', fn(Column $c) => $c->int())->addPrimary('id')->createTable('t')
        );
    }

    public function testAddPrimaryOnSeveralColumns()
    {
        self::assertStringContainsString(
            'PRIMARY KEY (`a`,`b`)',
            $this->fb()->addColumn('a')->addPrimary('a', 'b')->createTable('t')
        );
    }

    public function testDropPrimary()
    {
        self::assertSame(
            "ALTER TABLE `users` \n\tDROP PRIMARY KEY;",
            $this->fb()->dropPrimary()->alterTable('users')
        );
    }

    public function testAddIndexDefaultsToAColumnNamedAfterTheIndex()
    {
        self::assertSame(
            "ALTER TABLE `users` \n\tADD INDEX `users_name_idx` (`name` ASC);",
            $this->fb()->addIndex('name')->alterTable('users')
        );
    }

    public function testTheColsArgumentIsAColumnToDirectionMapNotAList()
    {
        // $cols is [column => isAscending]. Passing a plain list makes the array
        // KEYS the column names, so ['a'] produces (`0` ASC).
        self::assertStringContainsString(
            '(`a` ASC,`b` DESC)',
            $this->fb()->addIndex('combo', ['a' => true, 'b' => false])->alterTable('users')
        );
        self::assertStringContainsString(
            '(`0` ASC)',
            $this->fb()->addIndex('oops', ['a'])->alterTable('users')
        );
    }

    public function testAddUnique()
    {
        self::assertStringContainsString(
            'ADD UNIQUE INDEX `users_email_unq` (`email` ASC)',
            $this->fb()->addUnique('email')->alterTable('users')
        );
    }

    public function testAddFulltext()
    {
        self::assertStringContainsString(
            'ADD FULLTEXT INDEX `posts_body_txt` (`body` ASC)',
            $this->fb()->addFulltext('body')->alterTable('posts')
        );
    }

    /**
     * @dataProvider dropKeyProvider
     */
    public function testDropKeyVariantsAllEmitDropIndex(string $method, string $expected)
    {
        self::assertStringContainsString(
            "DROP INDEX `{$expected}`",
            $this->fb()->{$method}('x')->alterTable('users')
        );
    }

    public static function dropKeyProvider(): array
    {
        return [
            'index' => ['dropIndex', 'users_x_idx'],
            'unique' => ['dropUnique', 'users_x_unq'],
            'fulltext' => ['dropFulltext', 'users_x_txt'],
        ];
    }

    // -------------------------------------------------------------------------
    // Foreign keys
    // -------------------------------------------------------------------------

    public function testAddFk()
    {
        self::assertSame(
            "ALTER TABLE `users` \n\tADD CONSTRAINT `users_role_id_fk`"
                . " FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)"
                . " ON DELETE CASCADE ON UPDATE CASCADE;",
            $this->fb()->addFk('role_id', 'roles.id')->alterTable('users')
        );
    }

    public function testAddFkHonoursItsUpdateArgument()
    {
        self::assertStringContainsString(
            'ON DELETE SET NULL ON UPDATE RESTRICT',
            $this->fb()->addFk('role_id', 'roles.id', 'SET NULL', 'RESTRICT')->alterTable('users')
        );
    }

    /**
     * @dataProvider invalidFkTargetProvider
     */
    public function testAddFkRejectsATargetThatIsNotTableDotColumn(string $target)
    {
        // The guard used to call Exception::prepare() without 'throw' -- these are
        // FACTORIES, not throwers -- so it did nothing and execution fell through
        // to "Undefined array key 1" plus a corrupted statement.
        $this->expectException(Exception::class);
        $this->expectExceptionCode(Exception::PREPARE);
        $this->expectExceptionMessage("{$target} is not a valid target");

        $this->fb()->addFk('role_id', $target);
    }

    public static function invalidFkTargetProvider(): array
    {
        return [
            'no dot' => ['roles'],
            'empty' => [''],
            'two dots' => ['db.roles.id'],
        ];
    }

    public function testARejectedFkLeavesNothingBehind()
    {
        $fb = $this->fb();

        try {
            $fb->addFk('role_id', 'roles');
        } catch (Exception $e) {
            // expected
        }

        self::assertStringNotContainsString('CONSTRAINT', $fb->addColumn('x', fn(Column $c) => $c->int())->alterTable('users'));
    }

    public function testAddFkIndex()
    {
        self::assertStringContainsString(
            'ADD INDEX `users_role_id_fk_idx` (`role_id` ASC)',
            $this->fb()->addFkIndex('role_id')->alterTable('users')
        );
    }

    public function testDropFkAndDropFkIndex()
    {
        self::assertSame(
            "ALTER TABLE `users` \n\tDROP INDEX `users_role_id_fk_idx`,"
                . "\n\tDROP FOREIGN KEY `users_role_id_fk`;",
            $this->fb()->dropFk('role_id')->dropFkIndex('role_id')->alterTable('users')
        );
    }

    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    public function testATerminalDoesNotResetTheStagedState()
    {
        // Same policy as QB: a terminal reads the staged state, it does not
        // consume it. FB used to clear itself at every terminal, which made the
        // two builders behave in opposite ways.
        $fb = $this->fb()->addColumn('a', fn(Column $c) => $c->int());

        self::assertSame($fb->alterTable('users'), $fb->alterTable('users'));
    }

    public function testAReusedBuilderAccumulatesItsClauses()
    {
        // The flip side, and the reason to build one FB per statement.
        $fb = $this->fb();

        $first = $fb->addColumn('a', fn(Column $c) => $c->int())->alterTable('t');
        $second = $fb->addColumn('b', fn(Column $c) => $c->int())->alterTable('t');

        self::assertStringContainsString('`a`', $first);
        self::assertStringNotContainsString('`b`', $first);
        self::assertStringContainsString('`a`', $second);
        self::assertStringContainsString('`b`', $second);
    }

    public function testResetIsNotPartOfTheApi()
    {
        // The staged state is initialized by the constructor. There is no way
        // to clear a builder in place: build a new FB instead.
        self::assertFalse(method_exists(FB::class, 'reset'));
    }

    // -------------------------------------------------------------------------
    // Statics
    // -------------------------------------------------------------------------

    public function testQuoteWrapsAValueInSingleQuotes()
    {
        self::assertSame("'x'", FB::quote('x'));
    }

    /**
     * @dataProvider falsyProvider
     */
    public function testQuoteStillQuotesFalsyValues($value, string $expected)
    {
        // The guard used to be a truthiness check, so 0, '0', '' and false all
        // quoted to an empty string and emitted a bare DEFAULT.
        self::assertSame($expected, FB::quote($value));
    }

    public static function falsyProvider(): array
    {
        return [
            'int zero' => [0, "'0'"],
            'string zero' => ['0', "'0'"],
            'empty string' => ['', "''"],
            'false' => [false, "'0'"],
            'true' => [true, "'1'"],
        ];
    }

    public function testQuoteReturnsAnEmptyStringOnlyForNull()
    {
        // null is the one value with no literal to emit: callers read '' as
        // "omit the clause".
        self::assertSame('', FB::quote(null));
    }

    public function testQuoteDoublesEmbeddedSingleQuotes()
    {
        self::assertSame("'O''Brien'", FB::quote("O'Brien"));
        self::assertSame("''''", FB::quote("'"));
    }

    /**
     * @dataProvider validIdentifierProvider
     */
    public function testCheckIdentifierAcceptsAlnumAndReturnsItUnchanged(string $value)
    {
        self::assertSame($value, FB::checkIdentifier($value));
    }

    public static function validIdentifierProvider(): array
    {
        return [
            'letters' => ['users'],
            'mixed case' => ['UserRoles'],
            'digits' => ['t42'],
            // The guard is a truthiness check, so the empty string never reaches
            // the pattern and is returned as-is.
            'empty string' => [''],
        ];
    }

    /**
     * @dataProvider invalidIdentifierProvider
     */
    public function testCheckIdentifierRejectsAnythingElse(string $value)
    {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(Exception::PREPARE);
        $this->expectExceptionMessage("{$value} is not a valid identifier");

        FB::checkIdentifier($value);
    }

    public static function invalidIdentifierProvider(): array
    {
        return [
            'underscore' => ['user_roles'],
            'dot' => ['db.users'],
            'backtick' => ['`users`'],
            'space' => ['user table'],
            'injection' => ['users; DROP TABLE users'],
        ];
    }

    public function testCheckIdentifierIsNeverCalledInternally()
    {
        // It validates nothing on its own: FB still interpolates table, column and
        // index names verbatim. Call it yourself before passing anything derived
        // from a request.
        self::assertStringContainsString(
            '`user_roles`',
            $this->fb()->addColumn('x', fn(Column $c) => $c->int())->alterTable('user_roles')
        );
    }
}
