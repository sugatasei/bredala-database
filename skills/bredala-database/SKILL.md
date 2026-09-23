---
name: bredala-database
description: How to correctly build SQL queries and schema migrations, run them through PDO, and wire a database session handler using the sugatasei/bredala-database PHP library (namespace Bredala\Database — QB query builder, FB forge/DDL builder, Column, Query, SQLiteBuilder, DBInterface, PDO\DB, PDO\Factory, SessionHandler, Doc\Database, Exception). Use this whenever the project's composer.json requires sugatasei/bredala-database, code imports from Bredala\Database\*, or you're asked to write a SELECT/INSERT/UPDATE/DELETE, a repository method, a WHERE/IN/JOIN clause, a migration, a CREATE TABLE or ALTER TABLE, an index or foreign key, a transaction, or database-backed sessions in a PHP project that has this library available — even if the request is phrased generically like "fetch the users" or "add a column" or "store sessions in MySQL" without naming the library. Also check this before hand-writing SQL strings or raw PDO prepare/execute code in such a project, since this library replaces those and has non-obvious and in places broken behavior (mixing OR with AND in one group throws, an empty IN array matches nothing, no builder resets between terminals, Column::defaultValue implies notNull, FB::checkIdentifier is never called internally) that plain PHP code would not share.
---

# bredala-database

`sugatasei/bredala-database` is a low-level SQL toolkit for PHP 8.5+: two string builders (`QB` for queries, `FB` for DDL), a thin PDO wrapper, a database session handler, and a schema-introspection helper. It is **not** an ORM — no models, no relations, no migrations runner, no lazy loading. You get SQL strings plus bound parameters, and you execute them yourself.

Namespace: `Bredala\Database\*`. Source lives in `vendor/sugatasei/bredala-database/src/`; read it directly when you need an exact signature — this skill focuses on how the pieces fit together, and on the behavior that is surprising or outright wrong.

## Orientation

- `QB` — fluent query builder. `QB::create('table')`, chain clauses, then call **one terminal** (`read()`, `count()`, `insert()`, `update()`, `delete()`, …) which returns a `Query`.
- `Query` — an SQL string plus its bound values: `getStatement()` and `getData()`. That's what you hand to the driver.
- `FB` — DDL builder ("forge"). Stage columns and keys, then call a terminal (`createTable()`, `alterTable()`, …) which returns an **SQL string**. Like `QB`, it keeps its state: build one per statement.
- `Column` — a column definition, built fluently inside an `FB::addColumn()` callback.
- `DBInterface` / `PDO\DB` — execute a `Query`, then fetch with `one()`/`all()`/`next()`/`count()`.
- `PDO\Factory` — builds a `\PDO` with sane defaults (exceptions on, emulated prepares off).
- `SQLiteBuilder` — a minimal, separate SQLite DDL builder. Unrelated to `FB`.
- `SessionHandler` — `\SessionHandlerInterface` backed by a table.
- `Exception` — the package's exception, with `CONNECT`/`PREPARE`/`BIND`/`EXECUTE`/`TRANSACTION` codes. Its static methods are **factories, not throwers**.

For a method cheat-sheet see `references/api-reference.md`. For the complete list of traps and known defects, see `references/gotchas.md` — read it before writing any `WHERE` that mixes `OR` with `AND`, or any `FB` migration.

## Core recipes

### Reading

```php
use Bredala\Database\QB;

$query = QB::create('users u')
    ->select('u.id', 'u.email', 'r.name AS role')
    ->left('roles r', 'r.id = u.role_id')
    ->whereEq('u.active', 1)
    ->whereEq('u.deleted_at', null)      // -> IS NULL
    ->orderAsc('u.email')
    ->limit(50, 100)
    ->read();

$rows = $db->exec($query)->all();
```

`whereEq()`/`whereNot()` adapt to the value: `null` becomes `IS NULL`/`IS NOT NULL`, an array becomes `IN (?,?,…)`/`NOT IN (…)`, a `Query` becomes `IN (<subquery>)` with its bindings merged, and a scalar becomes `= ?`/`<> ?`.

**An empty array matches nothing** (`1 = 0`), and `whereNot($col, [])` matches everything
(`1 = 1`), so an empty id list needs no guard of its own. Beware that `whereNot($col, [])`
keeps the rows where the column is null while `whereNot($col, [1])` drops them — that is
SQL's three-valued logic, not the builder.

When the value is meant to be a set, prefer `whereIn()` / `whereNotIn()` / `orWhereIn()` / `orWhereNotIn()`: same SQL, but typed `array|Query`, so a scalar or `null` raises a `TypeError` instead of silently becoming `= ?` or `IS NULL`.

### OR always needs a group

A condition group is linked by **one operator only**: the second condition fixes it and every later one must match. Mixing `AND` and `OR` in the same group throws an `Exception` (code `Exception::BUILD`) instead of emitting flat SQL, because `a AND b OR c` is valid SQL that means `(a AND b) OR c`. Whenever an `OR` meets an `AND`, wrap it:

```php
QB::create('users')
    ->whereEq('active', 1)
    ->groupStart()
        ->whereEq('role', 'admin')
        ->orWhereEq('role', 'owner')
    ->groupEnd()
    ->read();
// WHERE active = ? AND (role = ? OR role = ?)
```

The group carries `OR` internally and links to its parent with `AND`: two operators, never in the same group. Without it the chain throws.

An empty group (`groupStart()->groupEnd()`) is dropped and does not fix the parent's operator, so a conditionally-filled group is safe. A missing `groupEnd()` is supplied by the terminal. `HAVING` follows the same rule and has its own `havingGroupStart()` / `orHavingGroupStart()` / `havingGroupEnd()`.

### Writing

```php
$query = QB::create('users')
    ->add('email', $email)             // bound: email = ?
    ->add('name', $name)
    ->addRaw('created_at', 'NOW()')    // inlined verbatim — never user input
    ->insert();

$db->exec($query);
$id = $db->getId();
```

```php
QB::create('users')
    ->add('name', $name)
    ->increment('login_count')         // login_count = login_count + 1
    ->addRaw('updated_at', 'NOW()')
    ->whereEq('id', $id)
    ->update();
```

`add()` binds a placeholder; `addRaw()`, `addListRaw()`, `increment()` and `decrement()` **inline their value into the SQL**. Never pass user input to those. `limit()`'s arguments are inlined too, but its `int` signature makes that safe.

Bulk insert uses a separate terminal and a different SQL layout:

```php
QB::create('users')->insertAll([
    ['email' => 'a@b.c', 'name' => 'A'],
    ['email' => 'd@e.f', 'name' => 'D'],
]);
```

### Executing

```php
use Bredala\Database\PDO\DB;
use Bredala\Database\PDO\Factory;

$db = DB::create(Factory::create('mysql:host=localhost;dbname=app;charset=utf8mb4', $user, $pass));

$row  = $db->exec($query)->one();     // null unless there is EXACTLY one row
$rows = $db->exec($query)->all();     // every row
while ($row = $db->exec($query)->next()) { /* streamed */ }
$n    = $db->exec($query)->count();   // affected rows -- writes only
```

`one()` returns `null` for zero rows *and* for two or more; add `limit(1)` when you
want the first of a set. `count()` is `rowCount()`, so it is only meaningful after a
write — on a `SELECT` it is driver dependent (SQLite reports `0`). Count with
`QB::count()` and read the `sum` column.

Transactions are explicit and the methods chain:

```php
$db->transaction();

try {
    $db->exec($insert);
    $db->exec($update);
    $db->commit();
} catch (\Throwable $e) {
    $db->rollback();
    throw $e;
}
```

### Migrations

```php
use Bredala\Database\Column;
use Bredala\Database\FB;

$fb = FB::create();

$sql = $fb
    ->addColumn('id', fn(Column $c) => $c->int()->autoIncrement())
    ->addColumn('email', fn(Column $c) => $c->varchar(190)->notNull())
    ->addColumn('created_at', fn(Column $c) => $c->timestamp()->defaultTimestamp())
    ->addPrimary('id')
    ->createTable('users', 'Application users');

$db->query($sql);

// a terminal RESETS the builder, so stage the next statement from scratch
$sql = $fb->addUnique('email')->alterTable('users');
$db->query($sql);
```

Three things to get right:

- **A terminal does not reset the staged state**, same policy as `QB`. A reused `FB` accumulates its clauses, so build one per statement.
- **Index `$cols` is a `column => isAscending` map, not a list.** `addIndex('name')` defaults to the column of the same name; `addIndex('combo', ['a' => true, 'b' => false])` gives `(a ASC, b DESC)`. Passing `['a']` silently indexes a column called `0`.
- **`addFk()`'s target must be `table.column`** — anything else throws (`Exception::PREPARE`) and records nothing.

### Sessions

```php
use Bredala\Database\SessionHandler;

session_set_save_handler(new SessionHandler($db, [
    'table' => 'sessions',   // defaults: table 'sessions', columns id / ts / data
]), true);
session_start();
```

The table needs an id column (`char(40)`), an unsigned int timestamp and a text/blob payload.

## Behavior to keep in mind while writing code

- **Mixing `OR` with `AND` in one group throws.** Use `groupStart()`/`groupEnd()` (or `havingGroupStart()`/`havingGroupEnd()`) to express a mix. A mixed-operator error raised while closing a group surfaces at the terminal call, not at the `where()` that caused it.
- **`whereEq($col, [])` matches nothing and `whereNot($col, [])` matches everything**, via `1 = 0` / `1 = 1`. No guard needed for an empty list.
- **`addRaw()`/`addListRaw()`/`increment()`/`decrement()` inline their value** with no escaping and no placeholder.
- **`Query::__toString()` is debug-only.** It interpolates values without escaping, so the result is not safe to execute.
- **No builder resets between terminals** — `QB`, `FB` and `SQLiteBuilder` all keep their state, so build one per statement.
- **`Column::defaultValue(null)` emits no `DEFAULT` but still applies `notNull()`.** For a nullable column, don't call it at all. Bools become `1`/`0`, falsy strings are quoted.
- **Parameters are always bound as strings.** On SQLite that breaks a comparison against an expression with no affinity: `having('COUNT(*) > ?', 1)` matches nothing. Inline the value when it is not user input.
- **`FB::checkIdentifier()` only accepts strictly alphanumeric names** — no `_`, no `.`. It is never called internally, so it protects nothing unless you call it yourself.
- **`Exception`'s static methods are factories.** Write `throw Exception::connect($msg)`; calling them without `throw` silently does nothing (which was exactly the bug in `FB::addFk()`'s target validation).
- **`Column::bool()` is not a bare type** — it also sets unsigned, not-null and `DEFAULT 0`.
- **`Column`'s output order is fixed** regardless of call order, and `first()` always wins over `after()`.
- **`SQLiteBuilder::add()` called twice returns the same statement** — like the other builders, it keeps its state.
- **Identifiers are not validated or escaped anywhere.** Table, column and alias names go into the SQL verbatim — never build them from user input.

Read `references/gotchas.md` for the rest (the exact generated whitespace, `DELETE  FROM`'s double space, `dropSchema` emitting `DROP DATABASE`, `insertAll`'s different layout, `Doc\*` requirements) before matching SQL byte-for-byte or debugging a migration.
