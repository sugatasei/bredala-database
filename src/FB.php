<?php

namespace Bredala\Database;

/**
 * Database forge builder
 */
class FB
{
    protected array $cols;
    protected array $primary;
    protected array $keys;
    protected array $fk;

    public function __construct()
    {
        $this->cols = ['add' => [], 'drop' => [], 'change' => []];
        $this->primary = ['add' => [], 'drop' => false];
        $this->keys = ['add' => [], 'drop' => []];
        $this->fk = ['add' => [], 'drop' => []];
    }

    public static function create(): static
    {
        return new static();
    }

    // -------------------------------------------------------------------------
    // Schema
    // -------------------------------------------------------------------------

    /**
     * Create schema
     */
    public function createSchema(string $name, string $charset = 'utf8mb4', string $collate = 'utf8mb4_general_ci'): string
    {
        $statement = "CREATE SCHEMA IF NOT EXISTS ";
        $statement .= "`{$name}`";
        if ($charset) {
            $statement .= " DEFAULT CHARACTER SET {$charset}";
            if ($collate) {
                $statement .= " DEFAULT COLLATE {$collate}";
            }
        }
        $statement .= ";";

        return $this->query($statement);
    }

    /**
     * Drop schema
     */
    public function dropSchema(string $name): string
    {
        return $this->query("DROP DATABASE IF EXISTS `{$name}`;");
    }

    // -------------------------------------------------------------------------
    // Tables
    // -------------------------------------------------------------------------

    /**
     * Create table
     */
    public function createTable(string $name, string $comment = ""): string
    {
        [$db, $table] = self::explodeName($name);

        $rows = [];

        // Columns
        foreach ($this->cols['add'] as $col) {
            $rows[] = (string) $col;
        }

        // Primary
        if ($this->primary['add']) {
            $col = implode(",", $this->primary['add']);
            $rows[] = "PRIMARY KEY ({$col})";
        }

        $types = [
            'UNIQUE' => 'unq',
            'FULLTEXT' => 'txt',
            'KEY' => 'idx'
        ];

        // Keys
        foreach ($this->keys['add'] as $key) {
            $lbl = $key['type'] ? $key['type'] . ' INDEX' : 'INDEX';
            $suf =  $types[$key['type'] ?? 'KEY'];
            $rows[] = "{$lbl} `{$table}_{$key['name']}_{$suf}` ({$key['cols']})";
        }

        foreach ($this->fk['add'] as $stmt) {
            $rows[] = sprintf($stmt, $table, $db ? "`{$db}`." : '');
        }

        $statement = "CREATE TABLE IF NOT EXISTS ";
        $statement .= self::quoteName($db, $table);
        $statement .= " (\n\t" . implode(",\n\t", $rows) . "\n)";
        if ($comment) $statement .= "\n\t COMMENT " . self::quote($comment);
        $statement .= ";";

        return $this->query($statement);
    }

    /**
     * Drop table
     */
    public function dropTable(string $name): string
    {
        $name = self::quoteIdentifier($name);
        return $this->query("DROP TABLE IF EXISTS {$name};");
    }

    /**
     * Rename table
     */
    public function renameTable(string $from, string $to): string
    {
        $from = self::quoteIdentifier($from);
        $to = self::quoteIdentifier($to);
        return $this->query("ALTER TABLE {$from} RENAME TO {$to};");
    }

    /**
     * Alter table
     *
     * Warning : combining several operations of different type, may cause SQL errors
     */
    public function alterTable(string $name): string
    {
        [$db, $table] = self::explodeName($name);

        $rows = [];

        // Columns
        foreach ($this->cols['drop'] as $col) {
            $rows[] = "DROP COLUMN `$col`";
        }

        foreach ($this->cols['add'] as $col) {
            $rows[] = "ADD COLUMN " . $col;
        }

        foreach ($this->cols['change'] as $col) {
            $rows[] = "CHANGE COLUMN `{$col['name']}` " . $col['col'];
        }

        // Primary
        if ($this->primary['drop']) {
            $rows[] = "DROP PRIMARY KEY";
        }

        if ($this->primary['add']) {
            $col = implode(",", $this->primary['add']);
            $rows[] = "ADD PRIMARY KEY ({$col})";
        }

        // Keys
        foreach ($this->keys['drop'] as $key) {
            $rows[] = "DROP INDEX `{$table}_{$key}`";
        }

        $types = [
            'UNIQUE' => 'unq',
            'FULLTEXT' => 'txt',
            'KEY' => 'idx'
        ];

        // Keys
        foreach ($this->keys['add'] as $key) {
            $lbl = $key['type'] ? $key['type'] . ' INDEX' : 'INDEX';
            $suf =  $types[$key['type'] ?? 'KEY'];
            $rows[] = "ADD {$lbl} `{$table}_{$key['name']}_{$suf}` ({$key['cols']})";
        }

        // Foreign keys
        foreach ($this->fk['drop'] as $fk) {
            $rows[] = "DROP FOREIGN KEY `{$table}_{$fk}_fk`";
        }

        foreach ($this->fk['add'] as $fk) {
            $rows[] =  "ADD " . sprintf($fk, $table, $db ? "`{$db}`." : '');
        }

        $statement = "ALTER TABLE " . self::quoteName($db, $table);
        $statement .= " \n\t" . implode(",\n\t", $rows) . ";";

        return $this->query($statement);
    }

    // -------------------------------------------------------------------------
    // Columns
    // -------------------------------------------------------------------------

    /**
     * Add a column
     */
    public function addColumn(string $name, ?callable $callback = null): static
    {
        $col = new Column($name);
        $this->cols['add'][] = $col;
        if ($callback) $callback($col);
        return $this;
    }

    /**
     * Drop a column
     */
    public function dropColumn(string $name): static
    {
        $this->cols['drop'][] = $name;
        return $this;
    }

    /**
     * Change a column
     */
    public function changeColumn(string $name, ?string $new_name = null, ?callable $callback = null): static
    {
        if (!$new_name) $new_name = $name;

        $col = new Column($new_name);
        $this->cols['change'][] = ['name' => $name, 'col' => $col];
        if ($callback) $callback($col);
        return $this;
    }

    // -------------------------------------------------------------------------
    // Primary
    // -------------------------------------------------------------------------

    /**
     * Add a primary key
     */
    public function addPrimary(...$names): static
    {
        foreach ($names as $name) {
            $this->primary['add'][] = "`{$name}`";
        }
        return $this;
    }

    /**
     * Drop primary key
     * @return $this
     */
    public function dropPrimary(): static
    {
        $this->primary['drop'] = true;
        return $this;
    }

    // -------------------------------------------------------------------------
    // Index
    // -------------------------------------------------------------------------

    /**
     * Add an index key
     *
     * @param string $name
     * @param array $cols
     * @return $this
     */
    public function addIndex(string $name, array $cols = []): static
    {
        $this->keys['add'][] = $this->buildKey($name, null, $cols);
        return $this;
    }

    /**
     * Add an unique key
     *
     * @param string $name
     * @param array $cols
     * @return $this
     */
    public function addUnique(string $name, array $cols = []): static
    {
        $this->keys['add'][] = $this->buildKey($name, 'UNIQUE', $cols);
        return $this;
    }

    /**
     * Add fulltext key
     *
     * @param string $name
     * @param array $cols
     * @return $this
     */
    public function addFulltext(string $name, array $cols = []): static
    {
        $this->keys['add'][] = $this->buildKey($name, 'FULLTEXT', $cols);
        return $this;
    }


    /**
     * Add an foreign key
     */
    private function buildKey(string $name, ?string $type, array $cols): array
    {
        if (!$cols) $cols[$name] = true;

        $c = [];
        foreach ($cols as $col => $asc) {
            $c[] = "`{$col}` " . ($asc ? 'ASC' : 'DESC');
        }

        return [
            'name' => $name,
            'type' => $type,
            'cols' => implode(',', $c)
        ];
    }

    /**
     * Drop index
     *
     * @param string $name
     * @return $this
     */
    public function dropIndex(string $name): static
    {
        $this->keys['drop'][] = $name . '_idx';
        return $this;
    }

    /**
     * Drop unique
     */
    public function dropUnique(string $name): static
    {
        $this->keys['drop'][] = $name . '_unq';
        return $this;
    }

    /**
     * Drop unique
     */
    public function dropFulltext(string $name): static
    {
        $this->keys['drop'][] = $name . '_txt';
        return $this;
    }

    // -------------------------------------------------------------------------
    // Foreign key
    // -------------------------------------------------------------------------

    /**
     * Add a foreign key
     */
    public function addFk(string $field, string $target, $delete = 'CASCADE', $update = 'CASCADE'): static
    {
        $target_array = explode('.', $target);

        if (count($target_array) !== 2) {
            $message = $target . " is not a valid target";
            throw Exception::prepare($message);
        }

        $statement = "CONSTRAINT `%s_{$field}_fk`";
        $statement .= " FOREIGN KEY (`{$field}`)";
        $statement .= " REFERENCES %s`{$target_array[0]}` (`$target_array[1]`)";
        $statement .= " ON DELETE {$delete}";
        $statement .= " ON UPDATE {$update}";

        $this->fk['add'][] = $statement;
        return $this;
    }

    /**
     * Add Foreign key index
     */
    public function addFkIndex(string $field): static
    {
        return $this->addIndex($field . '_fk', [$field => true]);
    }

    /**
     * Drop a foreign key
     */
    public function dropFk(string $field): static
    {
        $this->fk['drop'][] = $field;
        return $this;
    }

    /**
     * Drop Foreign key index
     */
    public function dropFkIndex(string $field): static
    {
        return $this->dropIndex($field . '_fk');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Check an identifier
     *
     * @param string $value
     * @return string
     */
    public static function checkIdentifier(string $value): string
    {
        if ($value && !preg_match("/^[a-zA-Z0-9]+$/", $value)) {
            $message = "{$value} is not a valid identifier";
            throw Exception::prepare($message);
        }

        return $value;
    }

    /**
     * @param string $statement
     * @return string
     */
    protected function query(string $statement): string
    {
        return $statement;
    }

    /**
     * Get db name and table from a name
     *
     * @param string $name
     * @return array
     */
    protected static function explodeName(string $name): array
    {
        $name = explode('.', $name);
        if (count($name) === 0) return ['', ''];
        if (count($name) === 1) return ['', $name[0]];
        return [$name[0], $name[1]];
    }

    /**
     * Quote name from a db name and a table name
     *
     * @param string $db
     * @param string $table
     * @return string
     */
    protected static function quoteName(string $db, string $table): string
    {
        if ($db) return "`{$db}`.`{$table}`";
        return "`{$table}`";
    }

    /**
     * Quote an identifier (table name or column name)
     *
     * @param string $field
     * @return string
     */
    protected static function quoteIdentifier(string $field): string
    {
        [$db, $table] = self::explodeName($field);
        return self::quoteName($db, $table);
    }

    /**
     * Quote a value as a SQL string literal
     *
     * Only null yields an empty string, meaning "no literal at all" — callers
     * use it to decide whether to emit the clause. Every other value, falsy
     * ones included, is quoted: '0', '' and false must not silently collapse
     * into a bare DEFAULT.
     *
     * @param scalar|null $value
     * @return string
     */
    public static function quote($value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }

        return "'" . str_replace("'", "''", (string) $value) . "'";
    }

    // -------------------------------------------------------------------------
}
