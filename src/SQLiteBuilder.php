<?php

namespace Bredala\Database;

/**
 * PRAGMA foreign_keys=off;
 * BEGIN TRANSACTION;
 * alter table => old, create table, insert into, delete old
 * COMMIT;
 * PRAGMA foreign_keys=on;
 */
class SQLiteBuilder
{
    private string $name;
    private array $columns = [];
    private array $fk = [];

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public static function create(string $name): static
    {
        return new static($name);
    }

    /**
     * @param string $type TEXT, INTEGER, NUMERIC, REAL or BLOB
     * @param string $opt  e.g. PRIMARY KEY AUTOINCREMENT, NOT NULL DEFAULT 0
     */
    public function col(string $name, string $type, string $opt = ''): static
    {
        $this->columns[] = trim("{$name} {$type} {$opt}");
        return $this;
    }

    public function pk(string ...$columns): static
    {
        $pk = "CONSTRAINT pk PRIMARY KEY (" . join(', ', $columns) . ")";
        array_unshift($this->fk, $pk);
        return $this;
    }

    public function fk(string $column, string $dest_table, string $dest_column): static
    {
        $this->fk[] = "CONSTRAINT fk_{$column} FOREIGN KEY ({$column}) REFERENCES {$dest_table}({$dest_column})";
        return $this;
    }

    /**
     * Create table
     */
    public function add(): string
    {
        $res = "CREATE TABLE {$this->name} (\n\t";
        $res .= implode(",\n\t", $this->columns);
        if ($this->fk) {
            $res .= ",\n\t" . implode(",\n\t", $this->fk);
        }
        $res .= "\n);";
        return $res;
    }

    public function rename(string $name): string
    {
        return "ALTER TABLE {$this->name} RENAME TO {$name};";
    }

    /**
     * Drop table
     */
    public function del(): string
    {
        return "DROP TABLE IF EXISTS {$this->name}";
    }

    public function index(string $column): string
    {
        return "CREATE INDEX idx_{$column} ON {$this->name} ({$column});";
    }

    public function unique(string $column): string
    {
        return "CREATE UNIQUE INDEX unq_{$column} ON {$this->name} ({$column});";
    }
}
