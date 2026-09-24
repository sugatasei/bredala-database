<?php

namespace Bredala\Database;

/**
 * Database query builder
 */
class QB
{
    /**
     * A condition group being built: the operator linking its parts ("AND",
     * "OR" or null while it holds less than two), the parts themselves already
     * rendered and indented, and the operator linking the group to its parent.
     */
    private const array EMPTY_FRAME = ['op' => null, 'parts' => [], 'prefix' => ''];

    private array $data_keys = [];
    private array $data_values = [];
    private array $data_raw_keys = [];
    private array $data_raw_values = [];
    private string $from_stmt = "";
    private string $group_stmt = "";
    private array $having_data = [];
    private array $having_stack = [self::EMPTY_FRAME];
    private bool $is_distinct = false;
    private string $join_stmt = "";
    private int $limit_nb = 0;
    private int $offset_nb = 0;
    private string $order_by = "";
    private string $select_stmt = "";
    private array $where_data = [];
    private array $where_stack = [self::EMPTY_FRAME];

    // -------------------------------------------------------------------------
    // Construct
    // -------------------------------------------------------------------------

    public function __construct(string $table = '')
    {
        $this->from_stmt = $table;
    }

    public static function create(string $table = ''): QB
    {
        return new static($table);
    }

    // -------------------------------------------------------------------------
    // Select
    // -------------------------------------------------------------------------

    public function select(string ...$cols): QB
    {
        if (!$cols) {
            return $this;
        }

        $this->select_stmt .= ",\n\t" . implode(",\n\t", $cols);

        return $this;
    }

    public function distinct(): QB
    {
        $this->is_distinct = true;
        return $this;
    }

    // -------------------------------------------------------------------------
    // Table
    // -------------------------------------------------------------------------

    public function join(string $table, string $cond): QB
    {
        return $this->_join($table, $cond);
    }

    public function left(string $table, string $cond): QB
    {
        return $this->_join($table, $cond, "LEFT");
    }

    public function right(string $table, string $cond): QB
    {
        return $this->_join($table, $cond, "RIGHT");
    }

    private function _join(string $table, string $cond, string $type = ""): QB
    {
        $this->join_stmt .= "\n" . trim($type . " JOIN " . $table . " ON " . $cond);
        return $this;
    }

    // -------------------------------------------------------------------------
    // Where
    // -------------------------------------------------------------------------

    public function where(string $statement, mixed ...$values): QB
    {
        return $this->_where("AND", $statement, $values);
    }

    public function orWhere(string $statement, mixed ...$values): QB
    {
        return $this->_where("OR", $statement, $values);
    }

    /**
     * Compares a field to a value: = for a scalar, IS NULL for null, IN for an
     * array or a Query
     */
    public function whereEq(string $field, mixed $value): QB
    {
        return $this->_whereAuto("AND", false, $field, $value);
    }

    public function whereNot(string $field, mixed $value): QB
    {
        return $this->_whereAuto("AND", true, $field, $value);
    }

    public function orWhereEq(string $field, mixed $value): QB
    {
        return $this->_whereAuto("OR", false, $field, $value);
    }

    public function orWhereNot(string $field, mixed $value): QB
    {
        return $this->_whereAuto("OR", true, $field, $value);
    }

    /**
     * Restrict a field to a set of values
     *
     * The explicit spelling of whereEq() with an array. whereEq() also accepts a
     * scalar, null and a Query, each with its own semantics; when the value is a
     * set, saying so at the call site removes the guesswork.
     *
     * An empty set matches nothing, and whereNotIn() with an empty set matches
     * everything.
     */
    public function whereIn(string $field, array|Query $values): QB
    {
        return $this->_whereAuto("AND", false, $field, $values);
    }

    public function whereNotIn(string $field, array|Query $values): QB
    {
        return $this->_whereAuto("AND", true, $field, $values);
    }

    public function orWhereIn(string $field, array|Query $values): QB
    {
        return $this->_whereAuto("OR", false, $field, $values);
    }

    public function orWhereNotIn(string $field, array|Query $values): QB
    {
        return $this->_whereAuto("OR", true, $field, $values);
    }

    public function groupStart(): QB
    {
        return $this->_group("AND");
    }

    public function orGroupStart(): QB
    {
        return $this->_group("OR");
    }

    public function groupEnd(): QB
    {
        $this->_groupEnd($this->where_stack, 'where');

        return $this;
    }

    private function _where(string $prefix, string $statement, array $values): QB
    {
        $this->_push($this->where_stack, $prefix, $statement, 'where');

        foreach ($values as $v) {
            $this->where_data[] = $v;
        }

        return $this;
    }

    private function _whereAuto(string $prefix, bool $not, string $field, mixed $value): QB
    {
        // Belonging to the empty set is false for every row, and not belonging to
        // it is true for every row. The empty array used to share the null branch
        // and emit IS NULL / IS NOT NULL, so a filter built from an empty id list
        // matched the rows whose column is null instead of matching none. IN ()
        // is not an option: SQLite accepts it, MySQL rejects it.
        if (is_array($value) && !$value) {
            return $this->_where($prefix, $not ? '1 = 1' : '1 = 0', []);
        }

        $statement = $not ? '<> ?' : '= ?';
        $values = [$value];

        if ($value === null) {
            $statement = $not ? 'IS NOT NULL' : 'IS NULL';
            $values = [];
        } elseif (is_array($value)) {
            $clause = '?' . str_repeat(",?", count($value) - 1);
            $statement = 'IN (' . $clause . ')';
            if ($not) $statement = "NOT " . $statement;
            $values = $value;
        } elseif ($value instanceof Query) {
            $clause = mb_substr($value->getStatement(), 0, -1);
            $statement = 'IN (' . $clause . ')';
            if ($not) $statement = "NOT " . $statement;
            $values = $value->getData();
        }

        return $this->_where($prefix, "{$field} {$statement}", $values);
    }

    private function _group(string $prefix): QB
    {
        $this->where_stack[] = ['op' => null, 'parts' => [], 'prefix' => $prefix];

        return $this;
    }

    // -------------------------------------------------------------------------
    // Condition groups (shared by WHERE and HAVING)
    // -------------------------------------------------------------------------

    /**
     * Appends a condition to the innermost open group.
     *
     * A group is linked by a single operator: the first condition sets it, and
     * any later condition must use the same one. Mixing AND and OR in one group
     * is rejected rather than emitted flat, because SQL binds AND tighter than
     * OR and the resulting query would be valid but mean something else than
     * the chain reads like.
     *
     * @throws Exception
     */
    private function _push(array &$frames, string $prefix, string $body, string $kind): void
    {
        $level = count($frames) - 1;
        $frame = &$frames[$level];

        if (!$frame['parts']) {
            $prefix = "";
        } elseif ($frame['op'] === null) {
            $frame['op'] = $prefix;
        } elseif ($frame['op'] !== $prefix) {
            throw Exception::build($this->_mixedOperators($frame['op'], $prefix, $kind));
        }

        $frame['parts'][] = "\n" . str_repeat("\t", $level + 1) . trim($prefix . " " . $body);
    }

    /**
     * Closes the innermost open group and folds it into its parent.
     *
     * An empty group is dropped instead of being rendered: it would emit "()"
     * and swallow the operator of the condition that follows.
     *
     * @throws Exception
     */
    private function _groupEnd(array &$frames, string $kind): void
    {
        if (count($frames) < 2) {
            return;
        }

        $frame = array_pop($frames);

        if (!$frame['parts']) {
            return;
        }

        $indent = str_repeat("\t", count($frames));
        $body = "(" . implode("", $frame['parts']) . "\n" . $indent . ")";

        $this->_push($frames, $frame['prefix'], $body, $kind);
    }

    /**
     * Closes every group left open and renders the root group.
     */
    private function _flush(array &$frames, string $kind): string
    {
        while (count($frames) > 1) {
            $this->_groupEnd($frames, $kind);
        }

        return implode("", $frames[0]['parts']);
    }

    private function _mixedOperators(string $current, string $added, string $kind): string
    {
        $start = $kind === 'having' ? 'havingGroupStart()' : 'groupStart()';
        $end = $kind === 'having' ? 'havingGroupEnd()' : 'groupEnd()';

        return "Cannot mix {$current} and {$added} in the same " . strtoupper($kind) . " group: "
            . "SQL binds AND tighter than OR, so 'a AND b OR c' means '(a AND b) OR c', "
            . "not the left-to-right reading of the chain. "
            . "Wrap the run in {$start} / {$end} to make the grouping explicit.";
    }

    // -------------------------------------------------------------------------
    // Group & Having
    // -------------------------------------------------------------------------

    public function groupBy(string ...$cols): QB
    {
        if ($cols) {
            $this->group_stmt .= ",\n\t" . join(",\n\t", $cols);
        }

        return $this;
    }

    public function having(string $statement, mixed ...$values): QB
    {
        return $this->_having("AND", $statement, $values);
    }

    public function orHaving(string $statement, mixed ...$values): QB
    {
        return $this->_having("OR", $statement, $values);
    }

    private function _having(string $prefix, string $statement, array $values): QB
    {
        $this->_push($this->having_stack, $prefix, $statement, 'having');

        foreach ($values as $v) {
            $this->having_data[] = $v;
        }

        return $this;
    }

    public function havingGroupStart(): QB
    {
        return $this->_havingGroup("AND");
    }

    public function orHavingGroupStart(): QB
    {
        return $this->_havingGroup("OR");
    }

    public function havingGroupEnd(): QB
    {
        $this->_groupEnd($this->having_stack, 'having');

        return $this;
    }

    private function _havingGroup(string $prefix): QB
    {
        $this->having_stack[] = ['op' => null, 'parts' => [], 'prefix' => $prefix];

        return $this;
    }

    // -------------------------------------------------------------------------
    // Order
    // -------------------------------------------------------------------------

    public function orderBy(string ...$cols): QB
    {
        return $this->_order($cols);
    }

    public function orderAsc(string ...$cols): QB
    {
        return $this->_order($cols, "ASC");
    }

    public function orderDesc(string ...$cols): QB
    {
        return $this->_order($cols, "DESC");
    }

    private function _order(array $cols, string $suffix = ""): QB
    {
        if (!$cols) {
            return $this;
        }

        if ($suffix) {
            $suffix = " " . $suffix;
        }

        $this->order_by .= ",\n\t" . implode($suffix . ",\n\t", $cols) . $suffix;

        return $this;
    }

    // -------------------------------------------------------------------------
    // Limit
    // -------------------------------------------------------------------------

    /**
     * A limit of 0 means no limit
     */
    public function limit(int $limit, int $offset = 0): QB
    {
        $this->limit_nb = $limit;
        $this->offset_nb = $offset;

        return $this;
    }

    // -------------------------------------------------------------------------
    // Set
    // -------------------------------------------------------------------------

    public function add(string $col, mixed $value): QB
    {
        $this->data_keys[] = $col;
        $this->data_values[] = $value;

        return $this;
    }

    /**
     * The value is inlined as an SQL expression, without placeholder: never
     * pass it user input
     */
    public function addRaw(string $col, string|int|float $value): QB
    {
        $this->data_raw_keys[] = $col;
        $this->data_raw_values[] = $value;

        return $this;
    }

    public function increment(string $col, int|float|string $val = 1): QB
    {
        return $this->addRaw($col, $col . ' + ' . $val);
    }

    public function decrement(string $col, int|float|string $val = 1): QB
    {
        return $this->addRaw($col, $col . ' - ' . $val);
    }

    /**
     * @param array<string, mixed> $data values keyed by column
     */
    public function addList(array $data): QB
    {
        foreach ($data as $k => $v) {
            $this->add($k, $v);
        }

        return $this;
    }

    /**
     * @param array<string, string|int|float> $data SQL expressions keyed by column
     */
    public function addListRaw(array $data): QB
    {
        foreach ($data as $k => $v) {
            $this->addRaw($k, $v);
        }

        return $this;
    }

    // -------------------------------------------------------------------------
    // Build
    // -------------------------------------------------------------------------

    public function read(): Query
    {
        $str = $this->_buildSelect()
            . $this->_buildFrom()
            . $this->_buildJoin()
            . $this->_buildWhere()
            . $this->_buildGroupBy()
            . $this->_buildHaving()
            . $this->_buildOrderBy()
            . $this->_buildLimit();

        $data = array_merge($this->where_data, $this->having_data);

        return new Query($str, $data);
    }

    /**
     * Counts the rows the read() query would return, aliased as "sum"
     */
    public function count(): Query
    {
        $str = "SELECT\n\tCOUNT(*) AS sum"
            . $this->_buildFrom()
            . $this->_buildJoin()
            . $this->_buildWhere()
            . $this->_buildGroupBy()
            . $this->_buildHaving()
            . $this->_buildOrderBy()
            . $this->_buildLimit();

        if ($this->group_stmt) {
            $str = "SELECT COUNT(*) as sum FROM (\n" . $str . "\n) as QBCOUNT";
        }

        $data = array_merge($this->where_data, $this->having_data);

        return new Query($str, $data);
    }

    public function insert(bool $ignore = false): Query
    {
        $str = $this->_buildInsert(false, $ignore);
        $data = $this->data_values;

        return new Query($str, $data);
    }

    public function replace(): Query
    {
        $str = $this->_buildInsert(true);
        $data = $this->data_values;

        return new Query($str, $data);
    }

    public function update(bool $ignore = false): Query
    {
        $str = $this->_buildUpdate($ignore);
        $data = array_merge($this->data_values, $this->where_data);

        return new Query($str, $data);
    }

    public function delete(): Query
    {
        $str = $this->_buildDelete()
            . $this->_buildJoin()
            . $this->_buildWhere()
            . $this->_buildOrderBy()
            . $this->_buildLimit();

        $data = $this->where_data;

        return new Query($str, $data);
    }

    /**
     * @param array<string, mixed>[] $data rows keyed by column, the columns being
     *                                     taken from the first row
     */
    public function insertAll(array $data, bool $ignore = false): Query
    {
        return $this->_batch($data, false, $ignore);
    }

    /**
     * @param array<string, mixed>[] $data rows keyed by column, the columns being
     *                                     taken from the first row
     */
    public function replaceAll(array $data): Query
    {
        return $this->_batch($data, true, false);
    }

    private function _batch(array $data, bool $replace, bool $ignore): Query
    {
        if (!$data) {
            return new Query();
        }

        $keys = array_keys($data[0]);
        $frag = "(" . join(",", array_fill(0, count($keys), "?")) . ")";

        $query_str = $replace ? "REPLACE " : "INSERT ";
        $query_str .= $ignore ? "IGNORE INTO " : "INTO ";
        $query_str .= $this->from_stmt . " (" . join(",", $keys) . ") VALUES \n";

        $query_data = [];

        foreach ($data as $sub) {
            $query_str .= $frag . ",\n";
            foreach (array_values($sub) as $v) {
                $query_data[] = $v;
            }
        }

        return new Query(mb_substr($query_str, 0, -2), $query_data);
    }

    private function _buildSelect(): string
    {
        if (!$this->select_stmt) {
            $this->select("*");
        }

        $distinct = $this->is_distinct ? " DISTINCT" : "";

        return "SELECT" . $distinct . mb_substr($this->select_stmt, 1);
    }

    private function _buildFrom(): string
    {
        return $this->from_stmt ? "\nFROM " . $this->from_stmt : "";
    }

    private function _buildJoin(): string
    {
        return $this->join_stmt;
    }

    private function _buildWhere(): string
    {
        $stmt = $this->_flush($this->where_stack, 'where');

        return $stmt ? "\nWHERE" . $stmt : "";
    }

    private function _buildGroupBy(): string
    {
        if ($this->group_stmt) {
            return "\nGROUP BY" . mb_substr($this->group_stmt, 1);
        }

        return "";
    }

    private function _buildHaving(): string
    {
        $stmt = $this->_flush($this->having_stack, 'having');

        return $stmt ? "\nHAVING" . $stmt : "";
    }

    private function _buildOrderBy(): string
    {
        if ($this->order_by) {
            return "\nORDER BY" . mb_substr($this->order_by, 1);
        }

        return "";
    }

    private function _buildLimit(): string
    {
        if ($this->limit_nb) {
            $sql = "\nLIMIT " . $this->limit_nb;

            if ($this->offset_nb) {
                $sql .= " OFFSET " . $this->offset_nb;
            }

            return $sql;
        }

        return "";
    }

    private function _buildInsert(bool $replace = false, bool $ignore = false): string
    {
        $keys = "";
        $values = "";
        $sep = ",\n\t\t";

        if ($this->data_keys) {
            $keys .= join($sep, $this->data_keys);
            $values .= mb_substr(str_repeat($sep . "?", count($this->data_keys)), 1);
        }

        if ($this->data_keys && $this->data_raw_keys) {
            $keys .= $sep;
            $values .= $sep;
        }

        if ($this->data_raw_keys) {
            $keys .= join($sep, $this->data_raw_keys);
            $values .= join($sep, $this->data_raw_values);
        }

        $ignore = $ignore ? " IGNORE" : "";

        return ($replace ? "REPLACE" : "INSERT" . $ignore)
            . " INTO\n\t" . $this->from_stmt
            . "(\n\t\t" . $keys . "\n\t)\n"
            . "VALUES \t(" . $values . "\n\t)";
    }

    private function _buildUpdate(bool $ignore = false): string
    {
        $ignore = $ignore ? "IGNORE " : "";
        $sql = "UPDATE " . $ignore . $this->from_stmt
            . $this->_buildJoin()
            . "\nSET";

        $update = "";

        foreach ($this->data_keys as $key) {
            $update .= ",\n\t" . $key . " = ?";
        }

        foreach ($this->data_raw_keys as $i => $key) {
            $update .= ",\n\t" . $key . " = " . $this->data_raw_values[$i];
        }

        if ($update) {
            $sql .= mb_substr($update, 1);
        }

        $sql .= $this->_buildWhere();

        return $sql;
    }

    private function _buildDelete(): string
    {
        $select = "";
        if ($this->join_stmt) {
            $from = explode(" ", $this->from_stmt);
            $select = $from[count($from) - 1];
        }

        return "DELETE " . $select . " FROM\n\t" . $this->from_stmt;
    }
}

/* End of file */
