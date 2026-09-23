# bredala-database — API cheat sheet

Quick lookup by intent. This is not exhaustive — read the source in `vendor/sugatasei/bredala-database/src/` for exact signatures/edge cases not covered here.

## QB (`Bredala\Database\QB`)

Fluent query builder. `QB::create(string $table = ''): QB` or `new QB($table)`. Every clause method returns `QB`; every **terminal** returns a `Query`. The builder is not reset by a terminal, so several terminals can be called on the same instance.

### Clauses

| Intent | Method |
| ------ | ------ |
| Columns (accumulates across calls) | `select(string ...$cols): QB` |
| `SELECT DISTINCT` | `distinct(): QB` |
| Joins | `join(string $table, string $cond)`, `left(...)`, `right(...)` |
| Raw condition with bindings | `where(string $statement, ...$values)` / `orWhere(...)` |
| Equality / inequality, value-adaptive | `whereEq(string $field, $value)` / `whereNot(...)` / `orWhereEq(...)` / `orWhereNot(...)` |
| Set membership, explicit | `whereIn(string $field, array\|Query $values)` / `whereNotIn(...)` / `orWhereIn(...)` / `orWhereNotIn(...)` — same SQL as `whereEq()` with an array; a scalar or `null` raises a `TypeError` |
| Parenthesised group | `groupStart()` / `orGroupStart()` / `groupEnd()` |
| Grouping | `groupBy(string ...$cols)` |
| Post-aggregate conditions | `having(string $statement, ...$values)` / `orHaving(...)` |
| Parenthesised `HAVING` group | `havingGroupStart()` / `orHavingGroupStart()` / `havingGroupEnd()` |
| Ordering (accumulates in call order) | `orderAsc(string ...$cols)` / `orderDesc(...)` / `orderBy(string ...$cols)` |
| Row window (inlined, not bound) | `limit(int $limit, int $offset = 0)` — a `0` limit is omitted |

`whereEq`/`whereNot` shape themselves by value type:

| Value | Emitted |
| ----- | ------- |
| scalar | `field = ?` / `field <> ?` |
| `null` | `field IS NULL` / `field IS NOT NULL` |
| non-empty array | `field IN (?,?,…)` / `field NOT IN (…)` |
| `[]` | `1 = 0` / `1 = 1` — matches nothing / everything |
| a `Query` | `field IN (<subquery>)`, bindings merged |

### Write data

| Intent | Method |
| ------ | ------ |
| Bound value | `add(string $col, $value): QB` |
| **Inlined** value, no placeholder | `addRaw(string $col, $value): QB` |
| Several bound / inlined values | `addList(array $data)` / `addListRaw(array $data)` |
| `col = col ± n`, via `addRaw` | `increment(string $col, $val = 1)` / `decrement(string $col, $val = 1)` |

### Terminals

All return `Query`. All take no table argument — the table comes from `QB::create()`.

`read()`, `count()` (`COUNT(*) AS sum`), `insert(bool $ignore = false)`, `replace()`, `update(bool $ignore = false)`, `delete()`, `insertAll(array $data, bool $ignore = false)`, `replaceAll(array $data)`.

## Query (`Bredala\Database\Query`)

`class Query implements QueryInterface`.

| Intent | Method |
| ------ | ------ |
| Build | `__construct(string $statement = '', array $data = [])` |
| Set / read the SQL | `setStatement(string $statement = ''): Query` / `getStatement(): string` |
| Set / read the bindings | `setData(array $data = []): Query` / `getData(): array` |
| Debug rendering | `__toString()` |

`setStatement()` trims and normalizes the SQL to exactly one trailing `;`. `__toString()` substitutes each bound value for the next `?` (strings wrapped in `'`, **no escaping**) — debug only.

`QueryInterface` declares just `getStatement(): string` and `getData(): array`.

## FB (`Bredala\Database\FB`)

MySQL DDL builder. `FB::create()` or `new FB()`. Staging methods return `$this`; terminals return an SQL `string`. The staged state is **not** reset by a terminal — same policy as `QB`, so build one `FB` per statement.

### Terminals

| Intent | Method |
| ------ | ------ |
| Create / drop a schema | `createSchema(string $name, string $charset = 'utf8mb4', string $collate = 'utf8mb4_general_ci'): string` / `dropSchema(string $name): string` (emits `DROP DATABASE`) |
| Create a table (always `IF NOT EXISTS`) | `createTable(string $name, string $comment = ""): string` |
| Drop / rename a table | `dropTable(string $name): string` / `renameTable(string $from, string $to): string` |
| Apply the staged changes | `alterTable(string $name): string` |

`$name` accepts `schema.table` and both parts are backquoted.

### Staging

| Intent | Method |
| ------ | ------ |
| Add a column (callback receives a `Column`) | `addColumn(string $name, ?callable $callback = null)` |
| Drop a column | `dropColumn(string $name)` |
| Redefine / rename a column | `changeColumn(string $name, ?string $new_name = null, ?callable $callback = null)` |
| Primary key | `addPrimary(...$names)` / `dropPrimary()` |
| Indexes — `$cols` is a `column => isAscending` **map** | `addIndex(string $name, array $cols = [])` / `addUnique(...)` / `addFulltext(...)` |
| Drop an index | `dropIndex(string $name)` / `dropUnique(...)` / `dropFulltext(...)` |
| Foreign keys (`$target` is `table.column`) | `addFk(string $field, string $target, $delete = 'CASCADE', $update = 'CASCADE')` / `dropFk(string $field)` |
| The index a foreign key needs | `addFkIndex(string $field)` / `dropFkIndex(string $field)` |

Generated key names are `` `{table}_{name}_{idx|unq|txt|fk}` ``. An empty `$cols` defaults to a single column named after the index. Emission order inside `ALTER TABLE` is fixed by the builder (drops before adds), not by call order.

### Statics

`quote($value): string` — wraps in `'` and doubles embedded quotes; falsy values are quoted too (`0` → `'0'`, `false` → `'0'`, `''` → `''`), only `null` returns an empty string. `checkIdentifier(string $value): string` — returns strictly alphanumeric names unchanged, throws (`Exception::PREPARE`) on anything else; the empty string slips through, and `FB` never calls it internally.

## Column (`Bredala\Database\Column`)

`new Column(string $name)`, rendered via `__toString()`. Defaults to `` `name` VARCHAR(255) NULL DEFAULT NULL ``. Every method is fluent.

**Types:** `type(string $type, ...$constraints)`, `int($prefix = '')` (`'big'`, `'tiny'`, …), `float()`, `decimal(int $precision = 10, int $scale = 2)`, `char(int $len)`, `varchar(int $len = 255)`, `text($prefix = '')`, `blob($prefix = '')`, `timestamp()`, `datetime()`, `date()`, `time()`, and `bool(bool $default = null)` which also sets unsigned, not-null and `DEFAULT 0`.

**Modifiers:** `unsigned(bool $value = true)`, `notNull()`, `defaultValue($value, bool $quote = true)` (implies `notNull()`), `defaultTimestamp($on_update = false)`, `autoIncrement(bool $value = true)` (implies `unsigned()->notNull()`), `comment(string $value)`, `first()`, `after(string $name)`.

Output order is fixed: name, type, `UNSIGNED`, `NOT NULL`/`NULL DEFAULT NULL`, `AUTO_INCREMENT` or `DEFAULT x`, `COMMENT`, `FIRST`/`AFTER`. `first()` wins over `after()`.

## SQLiteBuilder (`Bredala\Database\SQLiteBuilder`)

Minimal SQLite DDL, independent of `FB`. `SQLiteBuilder::create(string $name): static`. The state is **not** reset by a terminal.

`col(string $name, string $type, string $opt = ''): static`, `pk(string ...$columns)`, `fk(string $column, string $dest_table, string $dest_column): static`; terminals `add(): string` (CREATE TABLE), `rename(string $name): string`, `del(): string` (no trailing `;`), `index(string $column): string`, `unique(string $column): string`.

Index names are `idx_{column}` / `unq_{column}` with no table prefix.

## DBInterface / PDO\DB

`Bredala\Database\PDO\DB implements DBInterface`. Build with `new DB(\PDO $pdo)` or `DB::create(\PDO $pdo)`. Constants `HOOK_BEFORE_QUERY = 'before_query'`, `HOOK_AFTER_QUERY = 'after_query'`.

| Intent | Method |
| ------ | ------ |
| Run a built query | `exec(QueryInterface $query)` |
| Raw SQL / manual prepare | `query(string $sql)` / `prepare(string $statement)` / `bind(...$params)` / `execute(array $data = [])` |
| Fetch | `one()` (exactly one row, else null), `all()`, `next()`, `count()` (`rowCount()` — writes only) |
| Last insert id | `getId(): int` |
| Transactions | `transaction()`, `commit()`, `rollback()` |
| Switch database | `use(string $database)` |
| Escape a value | `escape(string $str): string` |
| Foreign key checks | `disableFkCheck()`, `enableFkCheck()` |
| Hooks | `addHook(string $hook, callable $callback)`, `execHook(string $hook, array $params = [])` |

Every mutator returns `DBInterface`, so calls chain.

## PDO\Factory

`static create(string $dsn, $username = null, $password = null, array $options = []): \PDO` — forces `ERRMODE_EXCEPTION`, `EMULATE_PREPARES = false` and `STRINGIFY_FETCHES = false` unless overridden, and wraps a `PDOException` into `Bredala\Database\Exception` with code `CONNECT`.

## SessionHandler

`Bredala\Database\SessionHandler implements \SessionHandlerInterface`.

`__construct(DBInterface $driver, array $options = [])` — options `table` (default `sessions`), `id` (`id`), `time` (`ts`), `data` (`data`). Implements `open`, `read`, `write`, `destroy`, `close`, `gc`.

## Doc\* — schema introspection

`Doc\Database`: `__construct(DBInterface $db, string $dbname)` / `static create(...)`, `run(): DocTable[]`. Queries MySQL's `information_schema`, so it needs a live MySQL connection.

`Doc\DocTable`: public `$name`, `$comment`, `$fields` (`DocField[]`). `Doc\DocField`: public `$name`, `$idx`, `$type`, `$ref`, `$value`, `$comment`.

## Exception

`Bredala\Database\Exception extends \Exception`. Codes `CONNECT = 1`, `PREPARE = 2`, `BIND = 3`, `EXECUTE = 4`, `TRANSACTION = 5`.

Static **factories** — they build and return, they do **not** throw: `connect(string $msg, \Throwable $prev = null)`, `prepare(...)`, `bind(...)`, `execute(...)`, `transaction(...)`. Always write `throw Exception::connect($msg)`.
