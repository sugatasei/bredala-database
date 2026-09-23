<?php

use Bredala\Database\Exception;
use Bredala\Database\QB;
use Bredala\Database\Query;
use PHPUnit\Framework\TestCase;

class QBTest extends TestCase
{
    /**
     * The generated SQL is indented with tabs and newlines, so expectations are
     * byte-exact. This helper keeps them readable.
     */
    private function assertSql(string $expected, Query $query, array $data = [])
    {
        self::assertSame($expected, $query->getStatement());
        self::assertSame($data, $query->getData());
    }

    // -------------------------------------------------------------------------
    // Construction
    // -------------------------------------------------------------------------

    public function testCreateIsEquivalentToNew()
    {
        self::assertSame(
            (new QB('users'))->read()->getStatement(),
            QB::create('users')->read()->getStatement()
        );
    }

    public function testEveryBuilderMethodIsFluent()
    {
        $qb = QB::create('users');

        self::assertSame($qb, $qb->select('id'));
        self::assertSame($qb, $qb->distinct());
        self::assertSame($qb, $qb->whereEq('id', 1));
        self::assertSame($qb, $qb->limit(1));
    }

    public function testTerminalsReturnAQuery()
    {
        self::assertInstanceOf(Query::class, QB::create('users')->read());
    }

    // -------------------------------------------------------------------------
    // Select
    // -------------------------------------------------------------------------

    public function testReadSelectsEverythingByDefault()
    {
        $this->assertSql("SELECT\n\t*\nFROM users;", QB::create('users')->read());
    }

    public function testSelectListsTheColumns()
    {
        $this->assertSql(
            "SELECT\n\tid,\n\tname\nFROM users;",
            QB::create('users')->select('id', 'name')->read()
        );
    }

    public function testSelectAccumulatesAcrossCalls()
    {
        $this->assertSql(
            "SELECT\n\tid,\n\tname\nFROM users;",
            QB::create('users')->select('id')->select('name')->read()
        );
    }

    public function testDistinct()
    {
        $this->assertSql(
            "SELECT DISTINCT\n\tname\nFROM users;",
            QB::create('users')->distinct()->select('name')->read()
        );
    }

    public function testCount()
    {
        $this->assertSql("SELECT\n\tCOUNT(*) AS sum\nFROM users;", QB::create('users')->count());
    }

    public function testATableAliasIsPassedThrough()
    {
        $this->assertSql("SELECT\n\t*\nFROM users u;", QB::create('users u')->read());
    }

    // -------------------------------------------------------------------------
    // Joins
    // -------------------------------------------------------------------------

    public function testJoins()
    {
        $this->assertSql(
            "SELECT\n\t*\nFROM users u"
                . "\nJOIN roles r ON r.id=u.role_id"
                . "\nLEFT JOIN teams t ON t.id=u.team_id"
                . "\nRIGHT JOIN logs l ON l.id=u.log_id;",
            QB::create('users u')
                ->join('roles r', 'r.id=u.role_id')
                ->left('teams t', 't.id=u.team_id')
                ->right('logs l', 'l.id=u.log_id')
                ->read()
        );
    }

    // -------------------------------------------------------------------------
    // Where
    // -------------------------------------------------------------------------

    public function testWhereEqOnAScalarBindsAPlaceholder()
    {
        $this->assertSql(
            "SELECT\n\t*\nFROM users\nWHERE\n\tid = ?;",
            QB::create('users')->whereEq('id', 5)->read(),
            [5]
        );
    }

    public function testWhereNotOnAScalar()
    {
        $this->assertSql(
            "SELECT\n\t*\nFROM users\nWHERE\n\tid <> ?;",
            QB::create('users')->whereNot('id', 5)->read(),
            [5]
        );
    }

    public function testWhereEqOnNullBecomesIsNull()
    {
        $this->assertSql(
            "SELECT\n\t*\nFROM users\nWHERE\n\tdeleted_at IS NULL;",
            QB::create('users')->whereEq('deleted_at', null)->read()
        );
    }

    public function testWhereNotOnNullBecomesIsNotNull()
    {
        $this->assertSql(
            "SELECT\n\t*\nFROM users\nWHERE\n\tdeleted_at IS NOT NULL;",
            QB::create('users')->whereNot('deleted_at', null)->read()
        );
    }

    public function testWhereEqOnAnArrayBecomesIn()
    {
        $this->assertSql(
            "SELECT\n\t*\nFROM users\nWHERE\n\tid IN (?,?,?);",
            QB::create('users')->whereEq('id', [1, 2, 3])->read(),
            [1, 2, 3]
        );
    }

    public function testWhereNotOnAnArrayBecomesNotIn()
    {
        $this->assertSql(
            "SELECT\n\t*\nFROM users\nWHERE\n\tid NOT IN (?,?);",
            QB::create('users')->whereNot('id', [1, 2])->read(),
            [1, 2]
        );
    }

    public function testWhereEqOnAnEmptyArrayMatchesNothing()
    {
        // Belonging to the empty set is false for every row. This used to take the
        // same branch as null and emit 'IS NULL', which matches rows. IN () is not
        // portable -- SQLite accepts it, MySQL rejects it -- hence the constant.
        $this->assertSql(
            "SELECT\n\t*\nFROM users\nWHERE\n\t1 = 0;",
            QB::create('users')->whereEq('id', [])->read()
        );
    }

    public function testWhereNotOnAnEmptyArrayMatchesEverything()
    {
        $this->assertSql(
            "SELECT\n\t*\nFROM users\nWHERE\n\t1 = 1;",
            QB::create('users')->whereNot('id', [])->read()
        );
    }

    public function testAnEmptyArrayCarriesNoPlaceholder()
    {
        $query = QB::create('users')->whereEq('a', 1)->whereEq('id', [])->read();

        self::assertSame([1], $query->getData());
        self::assertStringNotContainsString('id', $query->getStatement());
    }

    public function testAnEmptyArrayIsStillLinkedByTheGroupOperator()
    {
        $this->assertSql(
            "SELECT\n\t*\nFROM users\nWHERE\n\ta = ?\n\tOR 1 = 0;",
            QB::create('users')->whereEq('a', 1)->orWhereEq('id', [])->read(),
            [1]
        );
    }

    // -------------------------------------------------------------------------
    // whereIn / whereNotIn
    // -------------------------------------------------------------------------

    public function testWhereInIsTheExplicitSpellingOfWhereEqWithAnArray()
    {
        self::assertSame(
            QB::create('users')->whereEq('id', [1, 2])->read()->getStatement(),
            QB::create('users')->whereIn('id', [1, 2])->read()->getStatement()
        );
    }

    /**
     * @dataProvider inProvider
     */
    public function testTheInFamily(string $method, string $expected)
    {
        $this->assertSql(
            "SELECT\n\t*\nFROM users\nWHERE\n\ta = ?\n\t{$expected};",
            QB::create('users')->whereEq('a', 1)->{$method}('id', [2, 3])->read(),
            [1, 2, 3]
        );
    }

    public static function inProvider(): array
    {
        return [
            'whereIn' => ['whereIn', 'AND id IN (?,?)'],
            'whereNotIn' => ['whereNotIn', 'AND id NOT IN (?,?)'],
            'orWhereIn' => ['orWhereIn', 'OR id IN (?,?)'],
            'orWhereNotIn' => ['orWhereNotIn', 'OR id NOT IN (?,?)'],
        ];
    }

    public function testWhereInOnAnEmptyArrayMatchesNothing()
    {
        $this->assertSql(
            "SELECT\n\t*\nFROM users\nWHERE\n\t1 = 0;",
            QB::create('users')->whereIn('id', [])->read()
        );
    }

    public function testWhereNotInOnAnEmptyArrayMatchesEverything()
    {
        $this->assertSql(
            "SELECT\n\t*\nFROM users\nWHERE\n\t1 = 1;",
            QB::create('users')->whereNotIn('id', [])->read()
        );
    }

    public function testWhereInTakesASubQuery()
    {
        $sub = QB::create('roles')->select('id')->whereEq('name', 'admin')->read();

        $this->assertSql(
            "SELECT\n\t*\nFROM users\nWHERE\n\trole_id IN (SELECT\n\tid\nFROM roles\nWHERE\n\tname = ?);",
            QB::create('users')->whereIn('role_id', $sub)->read(),
            ['admin']
        );
    }

    /**
     * @dataProvider notASetProvider
     */
    public function testWhereInRejectsAnythingThatIsNotASet($value)
    {
        // The point of the method: whereEq() carries four semantics under a name
        // that announces one. whereIn() accepts a set and nothing else.
        $this->expectException(TypeError::class);

        QB::create('users')->whereIn('id', $value);
    }

    public static function notASetProvider(): array
    {
        return [
            'scalar' => [5],
            'string' => ['5'],
            'null' => [null],
        ];
    }

    public function testNullStillMeansIsNull()
    {
        // Only the empty array moved; null keeps its branch.
        $this->assertSql(
            "SELECT\n\t*\nFROM users\nWHERE\n\tid IS NULL;",
            QB::create('users')->whereEq('id', null)->read()
        );
    }

    public function testWhereTakesARawStatementAndItsBindings()
    {
        $this->assertSql(
            "SELECT\n\t*\nFROM users\nWHERE\n\tage > ? AND age < ?;",
            QB::create('users')->where('age > ? AND age < ?', 18, 65)->read(),
            [18, 65]
        );
    }

    public function testWhereEqAcceptsASubqueryAndMergesItsBindings()
    {
        $subquery = QB::create('bans')->select('user_id')->whereEq('active', 1)->read();

        $query = QB::create('users')->whereEq('id', $subquery)->read();

        self::assertStringContainsString('id IN (SELECT', $query->getStatement());
        self::assertSame([1], $query->getData());
    }

    public function testConditionsAreChainedWithAndByDefault()
    {
        $this->assertSql(
            "SELECT\n\t*\nFROM users\nWHERE\n\ta = ?\n\tAND b = ?;",
            QB::create('users')->whereEq('a', 1)->whereEq('b', 2)->read(),
            [1, 2]
        );
    }

    public function testMixingAndAndOrInTheSameGroupThrows()
    {
        // A flat 'a OR b AND c' is valid SQL that means 'a OR (b AND c)', not
        // the left-to-right reading of the chain: valid query, wrong rows, no
        // signal. The builder refuses it instead of emitting it silently.
        $this->expectException(Exception::class);
        $this->expectExceptionCode(Exception::BUILD);

        QB::create('users')->whereEq('a', 1)->orWhereEq('b', 2)->whereEq('c', 3);
    }

    public function testMixingAndAndOrThrowsWhateverTheOrder()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(Exception::BUILD);

        QB::create('users')->whereEq('tenant_id', 42)->where('a = ?', 1)->orWhere('b = ?', 2);
    }

    public function testTheMixedOperatorMessagePointsAtTheFix()
    {
        $this->expectExceptionMessageMatches('/groupStart\(\) \/ groupEnd\(\)/');

        QB::create('users')->whereEq('a', 1)->orWhereEq('b', 2)->whereEq('c', 3);
    }

    public function testAHomogeneousOrChainNeedsNoGroup()
    {
        $this->assertSql(
            "SELECT\n\t*\nFROM users\nWHERE\n\ta = ?\n\tOR b = ?\n\tOR c <> ?;",
            QB::create('users')->whereEq('a', 1)->orWhereEq('b', 2)->orWhereNot('c', 3)->read(),
            [1, 2, 3]
        );
    }

    public function testOrWhereNot()
    {
        $this->assertSql(
            "SELECT\n\t*\nFROM users\nWHERE\n\ta = ?\n\tOR b <> ?;",
            QB::create('users')->whereEq('a', 1)->orWhereNot('b', 2)->read(),
            [1, 2]
        );
    }

    public function testOrWhereTakesARawStatement()
    {
        $this->assertSql(
            "SELECT\n\t*\nFROM users\nWHERE\n\ta = ?\n\tOR b > ?;",
            QB::create('users')->whereEq('a', 1)->orWhere('b > ?', 2)->read(),
            [1, 2]
        );
    }

    // -------------------------------------------------------------------------
    // Groups
    // -------------------------------------------------------------------------

    public function testGroupStartAndGroupEndWrapConditions()
    {
        $this->assertSql(
            "SELECT\n\t*\nFROM users\nWHERE\n\tx = ?\n\tAND (\n\t\ta = ?\n\t\tOR b = ?\n\t);",
            QB::create('users')
                ->whereEq('x', 1)
                ->groupStart()
                ->whereEq('a', 2)
                ->orWhereEq('b', 3)
                ->groupEnd()
                ->read(),
            [1, 2, 3]
        );
    }

    public function testOrGroupStart()
    {
        $this->assertSql(
            "SELECT\n\t*\nFROM users\nWHERE\n\tx = ?\n\tOR (\n\t\ta = ?\n\t);",
            QB::create('users')
                ->whereEq('x', 1)
                ->orGroupStart()
                ->whereEq('a', 2)
                ->groupEnd()
                ->read(),
            [1, 2]
        );
    }

    public function testAGroupIsolatesItsOperatorFromTheParent()
    {
        // The group carries OR internally and links to the parent with AND:
        // two different operators, but never inside the same group.
        $this->assertSql(
            "SELECT\n\t*\nFROM users\nWHERE\n\tx = ?\n\tAND (\n\t\ta = ?\n\t\tOR b = ?\n\t)\n\tAND c = ?;",
            QB::create('users')
                ->whereEq('x', 1)
                ->groupStart()
                ->whereEq('a', 2)
                ->orWhereEq('b', 3)
                ->groupEnd()
                ->whereEq('c', 4)
                ->read(),
            [1, 2, 3, 4]
        );
    }

    public function testGroupsNest()
    {
        $this->assertSql(
            "SELECT\n\t*\nFROM users\nWHERE\n\tx = ?"
                . "\n\tAND (\n\t\ta = ?\n\t\tOR (\n\t\t\tb = ?\n\t\t\tAND c = ?\n\t\t)\n\t);",
            QB::create('users')
                ->whereEq('x', 1)
                ->groupStart()
                ->whereEq('a', 2)
                ->orGroupStart()
                ->whereEq('b', 3)
                ->whereEq('c', 4)
                ->groupEnd()
                ->groupEnd()
                ->read(),
            [1, 2, 3, 4]
        );
    }

    public function testAnUnclosedGroupIsClosedByTheTerminal()
    {
        $this->assertSql(
            "SELECT\n\t*\nFROM users\nWHERE\n\tx = ?\n\tAND (\n\t\ta = ?\n\t\tOR b = ?\n\t);",
            QB::create('users')
                ->whereEq('x', 1)
                ->groupStart()
                ->whereEq('a', 2)
                ->orWhereEq('b', 3)
                ->read(),
            [1, 2, 3]
        );
    }

    public function testAnEmptyGroupIsDropped()
    {
        // A conditional group whose body never ran used to emit 'AND ( )' and
        // swallow the operator of the next condition, producing invalid SQL.
        $this->assertSql(
            "SELECT\n\t*\nFROM users\nWHERE\n\ta = ?\n\tAND b = ?;",
            QB::create('users')
                ->whereEq('a', 1)
                ->groupStart()
                ->groupEnd()
                ->whereEq('b', 2)
                ->read(),
            [1, 2]
        );
    }

    public function testAnEmptyGroupDoesNotSetTheOperatorOfItsParent()
    {
        $this->assertSql(
            "SELECT\n\t*\nFROM users\nWHERE\n\ta = ?\n\tOR b = ?;",
            QB::create('users')
                ->whereEq('a', 1)
                ->groupStart()
                ->groupEnd()
                ->orWhereEq('b', 2)
                ->read(),
            [1, 2]
        );
    }

    public function testGroupEndWithoutGroupStartIsANoOp()
    {
        $this->assertSql(
            "SELECT\n\t*\nFROM users\nWHERE\n\ta = ?;",
            QB::create('users')->groupEnd()->whereEq('a', 1)->groupEnd()->read(),
            [1]
        );
    }

    public function testGroupsFixTheOrPrecedenceTrap()
    {
        $query = QB::create('users')
            ->groupStart()
            ->whereEq('a', 1)
            ->orWhereEq('b', 2)
            ->groupEnd()
            ->whereEq('c', 3)
            ->read();

        self::assertStringContainsString("(\n\t\ta = ?\n\t\tOR b = ?\n\t)", $query->getStatement());
        self::assertStringContainsString('AND c = ?', $query->getStatement());
    }

    // -------------------------------------------------------------------------
    // Group by / having / order / limit
    // -------------------------------------------------------------------------

    public function testGroupByAndHaving()
    {
        $this->assertSql(
            "SELECT\n\trole,\n\tCOUNT(*) c\nFROM users"
                . "\nGROUP BY\n\trole"
                . "\nHAVING\n\tCOUNT(*) > ?\n\tOR role = ?;",
            QB::create('users')
                ->select('role', 'COUNT(*) c')
                ->groupBy('role')
                ->having('COUNT(*) > ?', 5)
                ->orHaving('role = ?', 'admin')
                ->read(),
            [5, 'admin']
        );
    }

    public function testMixingAndAndOrInHavingThrows()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(Exception::BUILD);

        QB::create('users')->having('a > ?', 1)->orHaving('b > ?', 2)->having('c > ?', 3);
    }

    public function testHavingGroups()
    {
        $this->assertSql(
            "SELECT\n\trole,\n\tCOUNT(*) c\nFROM users"
                . "\nGROUP BY\n\trole"
                . "\nHAVING\n\tCOUNT(*) > ?\n\tAND (\n\t\trole = ?\n\t\tOR role = ?\n\t);",
            QB::create('users')
                ->select('role', 'COUNT(*) c')
                ->groupBy('role')
                ->having('COUNT(*) > ?', 5)
                ->havingGroupStart()
                ->having('role = ?', 'admin')
                ->orHaving('role = ?', 'owner')
                ->havingGroupEnd()
                ->read(),
            [5, 'admin', 'owner']
        );
    }

    public function testOrHavingGroupStart()
    {
        $this->assertSql(
            "SELECT\n\t*\nFROM users\nHAVING\n\ta > ?\n\tOR (\n\t\tb > ?\n\t);",
            QB::create('users')
                ->having('a > ?', 1)
                ->orHavingGroupStart()
                ->having('b > ?', 2)
                ->havingGroupEnd()
                ->read(),
            [1, 2]
        );
    }

    public function testOrderVariantsAccumulateInCallOrder()
    {
        $this->assertSql(
            "SELECT\n\t*\nFROM users\nORDER BY\n\tname ASC,\n\tid DESC,\n\tx DESC;",
            QB::create('users')->orderAsc('name')->orderDesc('id')->orderBy('x DESC')->read()
        );
    }

    public function testLimit()
    {
        $this->assertSql("SELECT\n\t*\nFROM users\nLIMIT 10;", QB::create('users')->limit(10)->read());
    }

    public function testLimitWithAnOffset()
    {
        $this->assertSql(
            "SELECT\n\t*\nFROM users\nLIMIT 10 OFFSET 20;",
            QB::create('users')->limit(10, 20)->read()
        );
    }

    public function testAZeroLimitIsOmitted()
    {
        $this->assertSql("SELECT\n\t*\nFROM users;", QB::create('users')->limit(0)->read());
    }

    public function testLimitAndOffsetAreInlinedNotBound()
    {
        // They are interpolated into the SQL, so they must never come straight
        // from user input without an (int) cast -- the signature enforces it here.
        self::assertSame([], QB::create('users')->limit(10, 20)->read()->getData());
    }

    // -------------------------------------------------------------------------
    // Insert
    // -------------------------------------------------------------------------

    public function testInsert()
    {
        $this->assertSql(
            "INSERT INTO\n\tusers(\n\t\tname,\n\t\tage\n\t)\nVALUES \t(\n\t\t?,\n\t\t?\n\t);",
            QB::create('users')->add('name', 'Tom')->add('age', 30)->insert(),
            ['Tom', 30]
        );
    }

    public function testInsertIgnore()
    {
        self::assertStringStartsWith(
            'INSERT IGNORE INTO',
            QB::create('users')->add('a', 1)->insert(true)->getStatement()
        );
    }

    public function testReplace()
    {
        self::assertStringStartsWith(
            'REPLACE INTO',
            QB::create('users')->add('a', 1)->replace()->getStatement()
        );
    }

    public function testAddList()
    {
        $this->assertSql(
            "INSERT INTO\n\tusers(\n\t\ta,\n\t\tb\n\t)\nVALUES \t(\n\t\t?,\n\t\t?\n\t);",
            QB::create('users')->addList(['a' => 1, 'b' => 2])->insert(),
            [1, 2]
        );
    }

    public function testInsertAllUsesADifferentCompactLayout()
    {
        $this->assertSql(
            "INSERT INTO users (a,b) VALUES \n(?,?),\n(?,?);",
            QB::create('users')->insertAll([['a' => 1, 'b' => 2], ['a' => 3, 'b' => 4]]),
            [1, 2, 3, 4]
        );
    }

    public function testReplaceAll()
    {
        $this->assertSql(
            "REPLACE INTO users (a) VALUES \n(?),\n(?);",
            QB::create('users')->replaceAll([['a' => 1], ['a' => 2]]),
            [1, 2]
        );
    }

    // -------------------------------------------------------------------------
    // Update / delete
    // -------------------------------------------------------------------------

    public function testUpdate()
    {
        $this->assertSql(
            "UPDATE users\nSET\n\tname = ?\nWHERE\n\tid = ?;",
            QB::create('users')->add('name', 'Tom')->whereEq('id', 5)->update(),
            ['Tom', 5]
        );
    }

    public function testUpdateIgnore()
    {
        self::assertStringStartsWith(
            'UPDATE IGNORE users',
            QB::create('users')->add('a', 1)->update(true)->getStatement()
        );
    }

    public function testDeleteHasADoubleSpace()
    {
        // The ignore flag is interpolated unconditionally, leaving 'DELETE  FROM'.
        $this->assertSql(
            "DELETE  FROM\n\tusers\nWHERE\n\tid = ?;",
            QB::create('users')->whereEq('id', 5)->delete(),
            [5]
        );
    }

    public function testAddRawIsInlinedWithoutBinding()
    {
        // No placeholder: the value goes straight into the SQL. Never pass user
        // input to addRaw()/addListRaw().
        $this->assertSql(
            "UPDATE users\nSET\n\tupdated_at = NOW();",
            QB::create('users')->addRaw('updated_at', 'NOW()')->update()
        );
    }

    public function testAddListRaw()
    {
        $this->assertSql(
            "UPDATE users\nSET\n\ta = NOW();",
            QB::create('users')->addListRaw(['a' => 'NOW()'])->update()
        );
    }

    public function testIncrement()
    {
        $this->assertSql(
            "UPDATE users\nSET\n\thits = hits + 1;",
            QB::create('users')->increment('hits')->update()
        );
    }

    public function testIncrementByAStep()
    {
        $this->assertSql(
            "UPDATE users\nSET\n\thits = hits + 5;",
            QB::create('users')->increment('hits', 5)->update()
        );
    }

    public function testDecrement()
    {
        $this->assertSql(
            "UPDATE users\nSET\n\tstock = stock - 5;",
            QB::create('users')->decrement('stock', 5)->update()
        );
    }

    public function testIncrementGoesThroughAddRawSoItIsNotBound()
    {
        self::assertSame([], QB::create('users')->increment('hits')->update()->getData());
    }

    public function testBoundAndRawValuesCanBeMixed()
    {
        $this->assertSql(
            "UPDATE users\nSET\n\tname = ?,\n\tupdated_at = NOW()\nWHERE\n\tid = ?;",
            QB::create('users')
                ->add('name', 'Tom')
                ->addRaw('updated_at', 'NOW()')
                ->whereEq('id', 5)
                ->update(),
            ['Tom', 5]
        );
    }

    // -------------------------------------------------------------------------
    // Reuse
    // -------------------------------------------------------------------------

    public function testTheBuilderIsReusableAndDoesNotResetBetweenTerminals()
    {
        $qb = QB::create('users')->whereEq('id', 5);

        self::assertSame([5], $qb->read()->getData());
        self::assertSame([5], $qb->count()->getData());
    }

    public function testTerminalsShareTheAccumulatedState()
    {
        // add() data feeds insert/update, where data feeds read/count/update/delete;
        // a builder carrying both produces valid SQL for several terminals.
        $qb = QB::create('users')->add('name', 'Tom')->whereEq('id', 5);

        self::assertSame(['Tom', 5], $qb->update()->getData());
        self::assertSame([5], $qb->delete()->getData());
    }
}
