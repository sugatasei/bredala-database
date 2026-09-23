<?php

use Bredala\Database\QB;

/**
 * The SQL QB generates, run through SQLite.
 *
 * The rest of the suite asserts on the generated string; these tests check that
 * the string is accepted by an engine and selects the rows it claims to. They
 * are the only place a precedence or placeholder mistake shows up as wrong data
 * rather than as a diff.
 */
class QbSqliteTest extends SqliteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles([[1, 'admin'], [2, 'member'], [3, 'guest']]);
        $this->seedUsers([
            [1, 'alice', 'alice@example.com', 1],
            [2, 'bob', 'bob@example.com', 1],
            [2, 'carol', 'carol@example.com', 0],
            [null, 'dave', null, 1],
        ]);
    }

    /**
     * @return string[] the names the query selects, in order
     */
    private function names(QB $qb): array
    {
        return array_column($this->db->exec($qb->select('name')->orderAsc('name')->read())->all(), 'name');
    }

    // -------------------------------------------------------------------------
    // Where
    // -------------------------------------------------------------------------

    public function testWhereEq()
    {
        self::assertSame(['alice'], $this->names(QB::create('users')->whereEq('name', 'alice')));
    }

    public function testWhereNot()
    {
        self::assertSame(['bob', 'carol', 'dave'], $this->names(QB::create('users')->whereNot('name', 'alice')));
    }

    public function testWhereEqWithAListBecomesIn()
    {
        self::assertSame(
            ['alice', 'carol'],
            $this->names(QB::create('users')->whereEq('name', ['alice', 'carol']))
        );
    }

    public function testWhereEqWithNullBecomesIsNull()
    {
        self::assertSame(['dave'], $this->names(QB::create('users')->whereEq('role_id', null)));
    }

    public function testWhereEqWithAnEmptyListMatchesNothing()
    {
        // This used to share the null branch and emit IS NULL, so a filter built
        // from an empty id list returned dave -- the row whose role_id is null.
        self::assertSame([], $this->names(QB::create('users')->whereEq('role_id', [])));
    }

    public function testWhereNotWithAnEmptyListMatchesEverything()
    {
        self::assertSame(
            ['alice', 'bob', 'carol', 'dave'],
            $this->names(QB::create('users')->whereNot('role_id', []))
        );
    }

    public function testTheEmptyListIsNotTheLimitOfTheNonEmptyOne()
    {
        // SQL's three-valued logic, not a defect of the empty-list handling:
        // NULL NOT IN (1) is NULL, not true, so dave is dropped here while
        // whereNot($col, []) keeps him. Worth knowing before relying on either.
        self::assertSame(['bob', 'carol'], $this->names(QB::create('users')->whereNot('role_id', [1])));
    }

    public function testWhereInAndWhereNotIn()
    {
        self::assertSame(['alice', 'carol'], $this->names(QB::create('users')->whereIn('name', ['alice', 'carol'])));
        self::assertSame(['bob', 'dave'], $this->names(QB::create('users')->whereNotIn('name', ['alice', 'carol'])));
    }

    public function testWhereInWithAnEmptySetAndItsMirror()
    {
        self::assertSame([], $this->names(QB::create('users')->whereIn('role_id', [])));
        self::assertSame(
            ['alice', 'bob', 'carol', 'dave'],
            $this->names(QB::create('users')->whereNotIn('role_id', []))
        );
    }

    public function testWhereInTakesASubQuery()
    {
        $admins = QB::create('roles')->select('id')->whereEq('name', 'admin')->read();

        self::assertSame(['alice'], $this->names(QB::create('users')->whereIn('role_id', $admins)));
    }

    public function testASubQueryBecomesInWithItsParametersMerged()
    {
        $admins = QB::create('roles')->select('id')->whereEq('name', 'admin')->read();

        self::assertSame(['alice'], $this->names(QB::create('users')->whereEq('role_id', $admins)));
    }

    // -------------------------------------------------------------------------
    // Groups -- the OR/AND precedence fix, verified against the engine
    // -------------------------------------------------------------------------

    public function testAGroupScopesTheOrRun()
    {
        // Without the group this would be 'active = 1 AND name = alice OR name =
        // carol', which SQLite reads as '(active AND alice) OR carol' and which
        // would wrongly return the inactive carol.
        $qb = QB::create('users')
            ->whereEq('active', 1)
            ->groupStart()
            ->whereEq('name', 'alice')
            ->orWhereEq('name', 'carol')
            ->groupEnd();

        self::assertSame(['alice'], $this->names($qb));
    }

    public function testAHomogeneousOrChainNeedsNoGroup()
    {
        self::assertSame(
            ['alice', 'carol'],
            $this->names(QB::create('users')->whereEq('name', 'alice')->orWhereEq('name', 'carol'))
        );
    }

    public function testNestedGroups()
    {
        $qb = QB::create('users')
            ->whereEq('active', 1)
            ->groupStart()
            ->whereEq('role_id', null)
            ->orGroupStart()
            ->whereEq('role_id', 1)
            ->whereEq('name', 'alice')
            ->groupEnd()
            ->groupEnd();

        self::assertSame(['alice', 'dave'], $this->names($qb));
    }

    // -------------------------------------------------------------------------
    // Joins, grouping, ordering, paging
    // -------------------------------------------------------------------------

    public function testJoin()
    {
        $rows = $this->db->exec(
            QB::create('users u')
                ->select('u.name', 'r.name role')
                ->join('roles r', 'r.id = u.role_id')
                ->orderAsc('u.name')
                ->read()
        )->all();

        self::assertSame(['alice' => 'admin', 'bob' => 'member', 'carol' => 'member'], array_column($rows, 'role', 'name'));
    }

    public function testLeftJoinKeepsTheUnmatchedRow()
    {
        $rows = $this->db->exec(
            QB::create('users u')
                ->select('u.name', 'r.name role')
                ->left('roles r', 'r.id = u.role_id')
                ->orderAsc('u.name')
                ->read()
        )->all();

        self::assertNull(array_column($rows, 'role', 'name')['dave']);
    }

    public function testGroupByAndHaving()
    {
        $rows = $this->db->exec(
            QB::create('users')
                ->select('role_id', 'COUNT(*) c')
                ->groupBy('role_id')
                ->having('COUNT(*) > 1')
                ->read()
        )->all();

        self::assertSame([['role_id' => 2, 'c' => 2]], $rows);
    }

    public function testABoundIntegerComparedToAnExpressionMatchesNothingUnderSqlite()
    {
        // KNOWN LIMIT: PDO\DB::execute() hands the whole array to
        // PDOStatement::execute(), which binds every value as PARAM_STR. SQLite
        // applies a *column*'s affinity to the bound text -- so whereEq('active', 1)
        // is fine -- but an expression such as COUNT(*) has no affinity, and in
        // SQLite's type ordering an integer always sorts before any text. So
        // 2 > '1' is false and the HAVING matches nothing.
        $rows = $this->db->exec(
            QB::create('users')
                ->select('role_id', 'COUNT(*) c')
                ->groupBy('role_id')
                ->having('COUNT(*) > ?', 1)
                ->read()
        )->all();

        self::assertSame([], $rows);
    }

    public function testTheSameComparisonAgainstAColumnIsFine()
    {
        // Same bound string, but 'active' has INTEGER affinity, so SQLite converts.
        self::assertSame(
            ['alice', 'bob', 'dave'],
            $this->names(QB::create('users')->where('active >= ?', 1))
        );
    }

    public function testLimitAndOffset()
    {
        self::assertSame(
            ['bob'],
            array_column($this->db->exec(
                QB::create('users')->select('name')->orderAsc('name')->limit(1, 1)->read()
            )->all(), 'name')
        );
    }

    public function testLimitZeroMeansNoLimit()
    {
        self::assertCount(4, $this->db->exec(QB::create('users')->limit(0)->read())->all());
    }

    public function testCountAliasesItsResultAsSum()
    {
        $row = $this->db->exec(QB::create('users')->whereEq('active', 1)->count())->one();

        self::assertArrayNotHasKey('count', $row);
        self::assertSame(3, (int) $row['sum']);
    }

    // -------------------------------------------------------------------------
    // Writes
    // -------------------------------------------------------------------------

    public function testInsert()
    {
        $this->db->exec(QB::create('users')->add('name', 'erin')->add('role_id', 1)->insert());

        self::assertSame(['alice', 'bob', 'carol', 'dave', 'erin'], $this->names(QB::create('users')));
    }

    public function testInsertAll()
    {
        $this->db->exec(QB::create('users')->insertAll([
            ['name' => 'erin', 'role_id' => 1],
            ['name' => 'frank', 'role_id' => 2],
        ]));

        self::assertSame(['alice', 'bob', 'carol', 'dave', 'erin', 'frank'], $this->names(QB::create('users')));
    }

    public function testUpdate()
    {
        $this->db->exec(QB::create('users')->add('active', 0)->whereEq('name', 'alice')->update());

        self::assertSame(['bob', 'dave'], $this->names(QB::create('users')->whereEq('active', 1)));
    }

    public function testIncrementInlinesItsExpression()
    {
        $this->db->exec(QB::create('users')->increment('role_id', 1)->whereEq('name', 'bob')->update());

        self::assertSame(
            3,
            (int) $this->rows('SELECT role_id FROM users WHERE name = ?;', ['bob'])[0]['role_id']
        );
    }

    public function testDelete()
    {
        $this->db->exec(QB::create('users')->whereEq('name', ['bob', 'carol'])->delete());

        self::assertSame(['alice', 'dave'], $this->names(QB::create('users')));
    }

    public function testReplaceIsAcceptedBySqlite()
    {
        $this->db->exec(QB::create('roles')->add('id', 1)->add('name', 'owner')->replace());

        self::assertSame('owner', $this->rows('SELECT name FROM roles WHERE id = 1;')[0]['name']);
    }
}
