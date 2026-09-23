# bredala-database — gotchas

Things the method names don't tell you. Grouped by class. Every item is pinned by a test in `tests/`, except where it says the class is untested.

## QB — correctness traps

- **A condition group is linked by one operator only.** The second condition of a group fixes it (`AND` or `OR`) and every later one must match; `whereEq('a', 1)->orWhereEq('b', 2)->whereEq('c', 3)` throws `Exception` with code `Exception::BUILD`. This used to be emitted flat as `a = ? OR b = ? AND c = ?`, which SQL reads as `a OR (b AND c)` — valid query, wrong rows, no signal. **Wrap the `OR` run in `groupStart()`/`groupEnd()`** (or `orGroupStart()`) to express a mix. The group carries its own operator internally and links to its parent with another one, so nesting is how you combine them.
- **Empty groups are dropped, unclosed groups are closed by the terminal.** `groupStart()->groupEnd()` emits nothing and does not fix the parent's operator, so a conditionally-filled group is safe. A missing `groupEnd()` is supplied by `read()`/`count()`/…; a `groupEnd()` with no matching `groupStart()` is a no-op.
- **`HAVING` has the same rule and its own groups**: `havingGroupStart()`, `orHavingGroupStart()`, `havingGroupEnd()`. Note that a mixed-operator error raised while closing a group surfaces at the terminal call, not at the `where()`/`having()` that caused it.
- **An empty array matches nothing**, and its `whereNot()` mirror matches everything: belonging to the empty set is false for every row. The emitted condition is `1 = 0` / `1 = 1`, not `IN ()`, which SQLite accepts but MySQL rejects. This used to share the `null` branch and emit `IS NULL` / `IS NOT NULL`, so a filter built from an empty id list matched the rows whose column is null.
- **The empty case is not the limit of the non-empty one.** `whereNot($col, [])` keeps the rows where `$col IS NULL`, while `whereNot($col, [1])` drops them — `NULL NOT IN (1)` is `NULL`, not `true`. SQL's three-valued logic, not a quirk of the builder.
- **`addRaw()`, `addListRaw()`, `increment()` and `decrement()` inline their value into the SQL** with no placeholder and no escaping (`increment` and `decrement` are implemented on top of `addRaw`). They exist for expressions like `NOW()` or `col + 1`; passing user input to them is a direct SQL injection.
- **`limit()`'s arguments are inlined too**, not bound. The `int` type declarations make that safe, but don't mirror the pattern in your own extensions.
- **Identifiers are never validated or escaped.** Table names, column names, aliases, join conditions and raw `where()`/`having()` statements go into the SQL verbatim. Only *values* passed to `add()`/`whereEq()`/`where()`'s variadic are bound. Never build an identifier from a request parameter.
- **`Query::__toString()` is for debugging only.** It substitutes values for `?` with quotes but **no escaping**, so `"O'Brien"` renders as `'O'Brien'` — broken SQL. Never execute the result; use `getStatement()` plus `getData()`.

## QB — shape of the output

- **The terminals take no arguments.** `read()`, `insert()`, `update()`, `delete()` etc. all read the table from `QB::create($table)`. Passing a table to a terminal (`->read($table)`) is silently ignored, and if `QB::create()` was called with no table the generated SQL has no table at all — this is exactly the bug `SessionHandler` used to carry.
- **The builder does not reset between terminals.** `read()` then `count()` on the same instance both work and share the accumulated `where` data. Convenient for a paginated query, but it also means a reused builder accumulates clauses — build a fresh `QB` per query. `FB` and `SQLiteBuilder` behave the same way; `FB` used to clear itself at every terminal, which made the two opposite.
- **`whereIn()` / `whereNotIn()` / `orWhereIn()` / `orWhereNotIn()` are the explicit spelling** of `whereEq()` with a set, and emit the same SQL. They accept an array or a `Query` and nothing else — a scalar or `null` raises a `TypeError`. Prefer them when the value is a set: `whereEq()` carries four semantics under a name that announces one.
- **`select()` accumulates across calls** rather than replacing, and `orderAsc()`/`orderDesc()`/`orderBy()` append in call order.
- **The generated SQL is multi-line and tab-indented.** `read()` produces `"SELECT\n\t*\nFROM users;"`. Byte-exact string assertions must reproduce the whitespace; normalize it in tests if you'd rather not.
- **`DELETE  FROM` has a double space.** The ignore flag is interpolated unconditionally, leaving an empty slot. Harmless to MySQL, but it breaks naive string matching on the generated SQL.
- **`insertAll()`/`replaceAll()` use a completely different layout** from `insert()`/`replace()` — a compact `INSERT INTO users (a,b) VALUES \n(?,?),\n(?,?);` instead of the indented column block. Don't expect one format across the two paths.
- **`count()` aliases the result as `sum`**, not `count` — read `$row['sum']`.
- **A subquery is inlined with its own indentation**, producing oddly formatted but valid SQL, and its bindings are appended to the outer query's in the right order.
- **A zero limit is omitted entirely**, so `limit(0)` means "no limit", not "no rows".

## FB

- **A terminal does not reset the staged state.** `createTable()`, `alterTable()`, `dropTable()` … read what was staged without consuming it, so calling one twice returns the same statement and a reused builder accumulates its clauses. Build one `FB` per statement, the way `QB` is used. It used to clear itself at every terminal, which made the two builders behave in opposite ways; there is no longer any `reset()` — the staged state is set up by the constructor.
- **`addIndex()`'s `$cols` is a `column => isAscending` map, not a list.** `addIndex('name')` is the common case and defaults to a single column of the same name. `addIndex('combo', ['a' => true, 'b' => false])` gives `(\`a\` ASC,\`b\` DESC)`. Passing a plain list like `['a']` makes the array *keys* the column names, so you silently index a column called `0`.
- **`addFk()`'s target must be exactly `table.column`.** Anything else (`'roles'`, `''`, `'db.roles.id'`) throws `Exception` with code `Exception::PREPARE` and leaves the builder untouched — no constraint is recorded. This guard used to call `Exception::prepare()` without `throw`, so it did nothing and execution fell through to `Undefined array key 1` plus garbage SQL.
- **`quote()` quotes every value, falsy included**, doubling embedded single quotes: `quote(0)` and `quote('0')` give `'0'`, `quote('')` gives `''`, `quote(false)` gives `'0'`, `quote(true)` gives `'1'`. **Only `null` returns an empty string**, meaning "no literal at all" — callers read that as "omit the clause". It used to return `''` for every falsy value, which emitted bare `DEFAULT` clauses.
- **`checkIdentifier()` accepts strictly alphanumeric names only**, returning them unchanged; `_`, `.`, backticks, spaces and semicolons all throw `Exception` with code `Exception::PREPARE`. Two caveats: the empty string passes the truthiness guard and comes back as-is, and **the method is never called internally** — `FB` still interpolates tables, columns and index names verbatim. It is a tool you call yourself, not an automatic protection.
- **`createTable()` with no staged columns emits an empty body** (`CREATE TABLE IF NOT EXISTS \`t\` (\n\t\n);`), which MySQL rejects. There's no guard.
- **`createTable()` always adds `IF NOT EXISTS`**, so it silently does nothing when the table already exists — it will not tell you the schema diverged.
- **`dropSchema()` emits `DROP DATABASE`** while `createSchema()` emits `CREATE SCHEMA`. Synonyms in MySQL, but asymmetric if you grep the generated SQL.
- **Inside `ALTER TABLE`, the emission order is fixed by the builder, not by call order** — drops come before adds. A migration that drops and re-adds a column of the same name works; one that relies on the reverse order does not.
- **Generated key names are `{table}_{name}_{suffix}`** (`_idx`, `_unq`, `_txt`, `_fk`), and `dropIndex`/`dropUnique`/`dropFulltext` all emit `DROP INDEX` with the matching suffix. Pass the same short `$name` to the add and the drop, not the generated one.
- **Identifiers are backquoted but not validated.** A `schema.table` string is split on the dot and both halves quoted; anything else goes in as-is.

## Column

- **`bool()` is not a bare type declaration.** It emits `TINYINT(1) UNSIGNED NOT NULL DEFAULT 0` — unsigned, not-null and a default, all at once. The other type helpers only set the type.
- **`defaultValue()` stores the SQL literal to emit**, so a bool is converted to `1`/`0` first — the same convention `bool()` uses. `defaultValue(false)` gives `DEFAULT 0`; it used to emit a bare `DEFAULT`, since a bool skips the string branch and `false` interpolates to nothing. Falsy strings are quoted normally (`defaultValue('0')` → `DEFAULT '0'`).
- **`defaultValue(null)` emits no `DEFAULT` at all** yet still applies `notNull()`, because the render guard is `$default !== null`. For a nullable column, don't call `defaultValue()` at all.
- **`defaultValue()` implies `notNull()`** and **`autoIncrement()` implies `unsigned()->notNull()`** — you can't have a nullable column with a default through this API.
- **The output order is fixed regardless of call order.** `int()->comment('x')->unsigned()` and `int()->unsigned()->comment('x')` render identically. Don't expect the fluent order to shape the SQL.
- **`first()` wins over `after()` regardless of call order**, because `__toString()` checks `first` first. Calling both is not "last one wins".
- **Redeclaring the type replaces it silently** — `int()->text()` is a `TEXT` column.
- **`defaultTimestamp(true)` emits a double space** before `ON UPDATE CURRENT_TIMESTAMP`.

## SQLiteBuilder

- **`add()` comma-separates constraints like columns**, so a primary key plus a foreign key, or two foreign keys, produce SQL SQLite accepts. This used to join the constraint lines with `"\n\t"` instead of `",\n\t"`, a bug invisible with a single constraint. Declaring constraints in the `col()` `$opt` argument (`col('id', 'INTEGER', 'PRIMARY KEY')`) still works.
- **`del()` has no trailing semicolon**, unlike every other terminal.
- **Index names carry no table prefix** (`idx_{column}`, `unq_{column}`). SQLite index names are database-wide, so two tables with a column of the same name collide.
- **The state is not reset by a terminal**, the opposite of `FB`. `add()` can be called twice and returns the same SQL.
- **Nothing is quoted or validated** — identifiers and types go straight into the SQL.
- **`pk()` joins columns with `", "`** (comma **and** space), while `FB` joins with a bare comma. Another reason not to match these two builders' output with a shared assertion.

## Exception

- **The static methods are factories, not throwers.** `Exception::connect($msg)` builds and *returns* an exception. Calling one without `throw` compiles, runs, and does nothing — which was precisely the defect in `FB::addFk()`'s target validation. Always write `throw Exception::prepare($msg)`.
- **The failure kind lives in the exception code**, not in subclasses: `CONNECT`, `PREPARE`, `BIND`, `EXECUTE`, `TRANSACTION`. Switch on `getCode()`.

## SessionHandler

- **It used to generate SQL with no table.** It passed the table name to `QB`'s zero-argument terminals (`->read($this->table)`), which ignored it, while `QB::create()` was called with no table at all. Fixed by building with `QB::create($this->table)`; if you are reading an older copy in `vendor/`, check for that pattern before debugging session storage.
- **It needs a table you create yourself**: an id column (`char(40)`), an unsigned int timestamp, and a text/blob payload. There is no migration shipped.
- **`read()` returns `''` for an unknown session**, which PHP treats as an empty session rather than an error.
- **Column names are configurable but the table is not validated** — the option values go into the SQL verbatim.

## PDO\DB, PDO\Factory, Doc\* (`Doc\*` is not covered by this package's tests)

- **`exec()` returns the driver, not the rows.** Chain a fetch: `$db->exec($query)->one()`. Calling `exec()` alone runs the statement and discards the result set.
- **`one()` takes no arguments.** An older call style (`one(false)`) raises an `ArgumentCountError`.
- **`one()` means "exactly one row"**, not "the first row": zero rows and two-or-more rows both return `null`. Add an explicit `limit(1)` when you want the first of a set. The check cannot use `rowCount()` — PDO only guarantees it for statements that modify rows — so it probes for a second row instead. It used to gate on `rowCount() === 1`, which SQLite reports as `0` for any `SELECT`, so `one()` returned `null` for every query and `SessionHandler::read()` never restored a session.
- **`count()` is `PDOStatement::rowCount()`**, meaningful only after an `INSERT`/`UPDATE`/`DELETE`/`REPLACE`. On a `SELECT` it is driver dependent: SQLite reports `0`. Count in SQL with `QB::count()` and read the `sum` column.
- **Every parameter is bound as `PDO::PARAM_STR`**, because `execute($data)` hands the array to `PDOStatement::execute()`. Harmless on MySQL. On SQLite a comparison against a *column* still works (the column's affinity converts the text), but one against an *expression* with no affinity does not: `having('COUNT(*) > ?', 1)` compares an integer to `'1'`, and in SQLite's type ordering an integer always sorts before text, so the clause matches nothing. Inline the value (`having('COUNT(*) > 1')`) when it is not user input.
- **`use()`, `escape()`, `getId()` and the foreign-key toggles are MySQL-flavored.** `disableFkCheck()`/`enableFkCheck()` emit `SET FOREIGN_KEY_CHECKS`, which SQLite rejects — use `PRAGMA foreign_keys` there.
- **`Factory::create()` forces `ERRMODE_EXCEPTION`, `EMULATE_PREPARES = false` and `STRINGIFY_FETCHES = false`** unless you override them — passing your own `$options` can silently re-enable emulated prepares.
- **`Factory::create()` wraps a `PDOException` into `Bredala\Database\Exception`** with code `CONNECT`, so a `catch (PDOException)` around the connection never fires.
- **`Doc\Database` requires MySQL's `information_schema`** and a live connection; it is not portable to SQLite or Postgres.
