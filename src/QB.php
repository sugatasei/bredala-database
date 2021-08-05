<?php

namespace Bredala\Database;

/**
 * Database query builder
 */
class QB
{

    private $data_keys = [];
    private $data_values = [];
    private $data_raw_keys = [];
    private $data_raw_values = [];
    private $from_stmt = "";
    private $group_stmt = "";
    private $group_count = 0;
    private $group_level = 0;
    private $having_data = [];
    private $having_stmt = "";
    private $is_distinct = false;
    private $join_stmt = "";
    private $limit_nb = 0;
    private $offset_nb = 0;
    private $order_by = "";
    private $select_stmt = "";
    private $where_data = [];
    private $where_stmt = "";

    // -------------------------------------------------------------------------
    // Construct
    // -------------------------------------------------------------------------

    /**
     * @param string $table
     */
    public function __construct(string $table = '')
    {
        $this->from_stmt = $table;
    }

    /**
     * @param string $table
     * @return QB
     */
    public static function create(string $table = ''): QB
    {
        return new static($table);
    }

    // -------------------------------------------------------------------------
    // Select
    // -------------------------------------------------------------------------

    /**
     * Select fields
     *
     * @param string ...$cols
     * @return QB
     */
    public function select(string ...$cols): QB
    {
        if (!$cols) {
            return $this;
        }

        $this->select_stmt .= ",\n\t" . implode(",\n\t", $cols);

        return $this;
    }

    /**
     * Select distinct
     *
     * @return QB
     */
    public function distinct(): QB
    {
        $this->is_distinct = true;
        return $this;
    }

    // -------------------------------------------------------------------------
    // Table
    // -------------------------------------------------------------------------

    /**
     * Join
     *
     * @param string $table
     * @param string $cond
     * @return QB
     */
    public function join(string $table, string $cond): QB
    {
        return $this->_join($table, $cond);
    }

    /**
     * Left join
     *
     * @param string $table
     * @param string $cond
     * @return QB
     */
    public function left(string $table, string $cond): QB
    {
        return $this->_join($table, $cond, "LEFT");
    }

    /**
     * Right join
     *
     * @param string $table
     * @param string $cond
     * @return QB
     */
    public function right(string $table, string $cond): QB
    {
        return $this->_join($table, $cond, "RIGHT");
    }

    /**
     * @param string $table
     * @param string $cond
     * @param string $type
     * @return QB
     */
    private function _join(string $table, string $cond, $type = ""): QB
    {
        $this->join_stmt .= "\n" . trim($type . " JOIN " . $table . " ON " . $cond);
        return $this;
    }

    // -------------------------------------------------------------------------
    // Where
    // -------------------------------------------------------------------------

    /**
     * Where
     *
     * @param string $statement
     * @param  mixed $values
     * @return QB
     */
    public function where(string $statement, ...$values): QB
    {
        return $this->_where("AND", $statement, $values);
    }

    /**
     * Or Where
     *
     * @param string $statement
     * @param  mixed $values
     * @return QB
     */
    public function orWhere(string $statement, ...$values): QB
    {
        return $this->_where("OR", $statement, $values);
    }

    /**
     * @param string $field
     * @param mixed $value
     * @return QB
     */
    public function whereEq(string $field, $value): QB
    {
        return $this->_whereAuto("AND", false, $field, $value);
    }
    /**
     * @param string $field
     * @param mixed $value
     * @return QB
     */
    public function whereNot(string $field, $value): QB
    {
        return $this->_whereAuto("AND", true, $field, $value);
    }

    /**
     * @param string $field
     * @param mixed $value
     * @return QB
     */
    public function orWhereEq(string $field, $value): QB
    {
        return $this->_whereAuto("OR", false, $field, $value);
    }

    /**
     * @param string $field
     * @param mixed $value
     * @return QB
     */
    public function orWhereNot(string $field, $value): QB
    {
        return $this->_whereAuto("OR", true, $field, $value);
    }

    /**
     * Group Start
     *
     * @return QB
     */
    public function groupStart(): QB
    {
        return $this->_group("AND");
    }

    /**
     * Or Group Start
     *
     * @return QB
     */
    public function orGroupStart(): QB
    {
        return $this->_group("OR");
    }

    /**
     * Group End
     *
     * @return QB
     */
    public function groupEnd(): QB
    {
        if ($this->group_level > 0) {
            $this->where_stmt .= "\n" . str_repeat("\t", $this->group_level) . ")";
            $this->group_level--;
        }

        return $this;
    }

    /**
     * Where helper
     *
     * @param string $prefix
     * @param string $statement
     * @param array $values
     * @return QB
     */
    private function _where(string $prefix, string $statement, array $values): QB
    {
        // Add link keyword if not the first element of a group
        if (!$this->where_stmt || $this->group_count === 0) {
            $prefix = "";
        }

        $this->group_count++;

        foreach ($values as $v) {
            $this->where_data[] = $v;
        }

        $this->where_stmt .= "\n"
            . str_repeat("\t", $this->group_level + 1)
            . trim($prefix . " " . $statement);

        return $this;
    }

    /**
     * @param string $prefix
     * @param boolean $not
     * @param string $field
     * @param mixed $value
     * @return QB
     */
    private function _whereAuto(string $prefix, bool $not, string $field, $value): QB
    {
        $statement = $not ? '<> ?' : '= ?';
        $values = [$value];

        if ($value === null || (($isArray = is_array($value)) && !$value)) {
            $statement = $not ? 'IS NOT NULL' : 'IS NULL';
            $values = [];
        } elseif ($isArray) {
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

    /**
     * Group helper
     *
     * @param string $prefix
     * @return QB
     */
    private function _group(string $prefix): QB
    {
        if (!$this->where_stmt || $this->group_count === 0) {
            $prefix = "";
        }

        $this->where_stmt .= "\n" . str_repeat("\t", $this->group_level + 1) . trim($prefix . " (");
        $this->group_count = 0;
        $this->group_level++;

        return $this;
    }

    // -------------------------------------------------------------------------
    // Group & Having
    // -------------------------------------------------------------------------

    /**
     * Group By
     *
     * @param string $cols
     * @return QB
     */
    public function groupBy(string ...$cols): QB
    {
        if ($cols) {
            $this->group_stmt .= ",\n\t" . join(",\n\t", $cols);
        }

        return $this;
    }

    /**
     * Having
     *
     * @param string $statement
     * @param mixed $values
     * @return QB
     */
    public function having(string $statement, ...$values): QB
    {
        return $this->_having("AND", $statement, $values);
    }

    /**
     * Or Having
     *
     * @param string $statement
     * @param mixed $values
     * @return QB
     */
    public function orHaving(string $statement, ...$values): QB
    {
        return $this->_having("OR", $statement, $values);
    }

    /**
     * Having helper
     *
     * @param string $prefix
     * @param string $statement
     * @param array $values
     * @return QB
     */
    private function _having(string $prefix, string $statement, array $values): QB
    {
        if (!$this->having_stmt) {
            $prefix = "";
        }

        foreach ($values as $v) {
            $this->having_data[] = $v;
        }

        $this->having_stmt .= "\n\t" . trim($prefix . " " . $statement);

        return $this;
    }

    // -------------------------------------------------------------------------
    // Order
    // -------------------------------------------------------------------------

    /**
     * Order
     *
     * @param string $cols
     * @return QB
     */
    public function orderBy(string ...$cols): QB
    {
        return $this->_order($cols);
    }

    /**
     * Order Asc
     *
     * @param string $cols
     * @return QB
     */
    public function orderAsc(string ...$cols): QB
    {
        return $this->_order($cols, "ASC");
    }

    /**
     * Order Desc
     *
     * @param string $cols
     * @return QB
     */
    public function orderDesc(string ...$cols): QB
    {
        return $this->_order($cols, "DESC");
    }

    /**
     * Order helper
     *
     * @param array $cols
     * @param string $suffix
     * @return QB
     */
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
     * Limit
     *
     * @param integer $limit
     * @param integer $offset
     * @return QB
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

    /**
     * Add data
     *
     * @param string $col
     * @param mixed $value
     * @return QB
     */
    public function add(string $col, $value): QB
    {
        $this->data_keys[] = $col;
        $this->data_values[] = $value;

        return $this;
    }

    /**
     * Add raw data
     *
     * @param string $col
     * @param mixed $value
     * @return QB
     */
    public function addRaw(string $col, $value): QB
    {
        $this->data_raw_keys[] = $col;
        $this->data_raw_values[] = $value;

        return $this;
    }

    /**
     * Increment
     *
     * @param string $col
     * @param mixed $value
     * @return QB
     */
    public function increment(string $col, $val = 1): QB
    {
        return $this->addRaw($col, $col . ' + ' . $val);
    }

    /**
     * Decrement
     *
     * @param string $col
     * @param mixed $value
     * @return QB
     */
    public function decrement(string $col, $val = 1): QB
    {
        return $this->addRaw($col, $col . ' - ' . $val);
    }

    /**
     * Add set of data
     *
     * @param array $data
     * @return QB
     */
    public function addList(array $data): QB
    {
        foreach ($data as $k => $v) {
            $this->add($k, $v);
        }

        return $this;
    }

    /**
     * Add set of raw data
     *
     * @param array $data
     * @return QB
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

    /**
     * Read
     *
     * @return Query
     */
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
     * Count
     *
     * @return Query
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

    /**
     * Insert
     *
     * @param bool $ignore
     * @return Query
     */
    public function insert(bool $ignore = false): Query
    {
        $str = $this->_buildInsert(false, $ignore);
        $data = $this->data_values;

        return new Query($str, $data);
    }

    /**
     * Replace
     *
     * @return Query
     */
    public function replace(): Query
    {
        $str = $this->_buildInsert(true);
        $data = $this->data_values;

        return new Query($str, $data);
    }

    /**
     * Update
     *
     * @param bool $ignore
     * @return Query
     */
    public function update(bool $ignore = false): Query
    {
        $str = $this->_buildUpdate($ignore);
        $data = array_merge($this->data_values, $this->where_data);

        return new Query($str, $data);
    }

    /**
     * Delete
     *
     * @return Query
     */
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
     * @param array $data
     * @param boolean $ignore
     * @return Query
     */
    public function insertAll(array $data, bool $ignore = false): Query
    {
        return $this->_batch($data, false, $ignore);
    }

    /**
     * @param array $data
     * @return Query
     */
    public function replaceAll(array $data): Query
    {
        return $this->_batch($data, true, false);
    }

    /**
     * @param array $data
     * @param boolean $replace
     * @param boolean $ignore
     * @return Query
     */
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

    /**
     * @return string
     */
    private function _buildSelect(): string
    {
        if (!$this->select_stmt) {
            $this->select("*");
        }

        $distinct = $this->is_distinct ? " DISTINCT" : "";

        return "SELECT" . $distinct . mb_substr($this->select_stmt, 1);
    }

    /**
     * @return string
     */
    private function _buildFrom(): string
    {
        return $this->from_stmt ? "\nFROM " . $this->from_stmt : "";
    }

    /**
     * @return string
     */
    private function _buildJoin(): string
    {
        return $this->join_stmt;
    }

    /**
     * @return string
     */
    private function _buildWhere(): string
    {
        if (!$this->where_stmt) {
            return "";
        }

        while ($this->group_level > 0) {
            $this->groupEnd();
        }

        return "\nWHERE" . $this->where_stmt;
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
        if ($this->having_stmt) {
            return "\nHAVING" . $this->having_stmt;
        }

        return "";
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
