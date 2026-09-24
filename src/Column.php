<?php

namespace Bredala\Database;

class Column
{
    protected string $name;
    protected ?string $type = null;
    protected bool $unsigned = false;
    protected bool $auto_increment = false;
    protected bool $not_null = false;
    protected string|int|float|null $default = null;
    protected ?string $after = null;
    protected bool $first = false;
    protected ?string $comment = null;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->varchar();
    }

    public function type(string $type, int|string ...$constraints): static
    {
        $this->type = mb_strtoupper($type);
        if ($constraints) {
            $this->type .= '(' . implode(',', $constraints) . ')';
        }

        return $this;
    }

    public function bool(?bool $default = null): static
    {
        return $this->type('tinyint', 1)->unsigned()->defaultValue($default ? 1 : 0);
    }

    /**
     * @param string $prefix TINY, SMALL, MEDIUM or BIG
     */
    public function int(string $prefix = ''): static
    {
        return $this->type($prefix . 'int');
    }

    public function float(): static
    {
        return $this->type('float');
    }

    public function decimal(int $precision = 10, int $scale = 2): static
    {
        return $this->type('decimal', $precision, $scale);
    }

    public function char(int $len): static
    {
        return $this->type('char', $len);
    }

    public function varchar(int $len = 255): static
    {
        return $this->type('varchar', $len);
    }

    /**
     * @param string $prefix TINY, MEDIUM or LONG
     */
    public function text(string $prefix = ''): static
    {
        return $this->type($prefix . 'text');
    }

    /**
     * @param string $prefix TINY, MEDIUM or LONG
     */
    public function blob(string $prefix = ''): static
    {
        return $this->type($prefix . 'blob');
    }

    public function timestamp(): static
    {
        return $this->type('timestamp');
    }

    public function datetime(): static
    {
        return $this->type('datetime');
    }

    public function date(): static
    {
        return $this->type('date');
    }

    public function time(): static
    {
        return $this->type('time');
    }

    public function unsigned(bool $value = true): static
    {
        $this->unsigned = $value;
        return $this;
    }

    public function notNull(): static
    {
        $this->not_null = true;
        return $this;
    }

    /**
     * Set default value
     *
     * The value is stored as the SQL literal to emit, so a bool has to become 1
     * or 0 first: it does not take the string branch, and interpolating false
     * would yield nothing and emit a bare DEFAULT. Same convention as bool().
     *
     * @param bool $quote quote a string value; pass false for an expression
     *                    such as CURRENT_TIMESTAMP
     */
    public function defaultValue(string|int|float|bool|null $value, bool $quote = true): static
    {
        if (is_bool($value)) {
            $value = (int) $value;
        }

        if ($quote && is_string($value)) {
            $this->default = FB::quote($value);
        } else {
            $this->default = $value;
        }

        return $this->notNull();
    }

    /**
     * Default value for datetime columns
     *
     * @param bool $on_update add ON UPDATE CURRENT_TIMESTAMP
     */
    public function defaultTimestamp(bool $on_update = false): static
    {
        $command = 'CURRENT_TIMESTAMP';
        if ($on_update) $command .= '  ON UPDATE CURRENT_TIMESTAMP';
        return $this->defaultValue($command, false);
    }

    public function autoIncrement(bool $value = true): static
    {
        $this->auto_increment = $value;
        return $this->unsigned()->notNull();
    }

    public function comment(string $value): static
    {
        $this->comment = FB::quote($value);
        return $this;
    }

    public function first(): static
    {
        $this->first = true;
        return $this;
    }

    public function after(string $name): static
    {
        $this->after = $name;
        return $this;
    }

    public function __toString(): string
    {
        $str = "`{$this->name}` {$this->type}";

        if ($this->unsigned) {
            $str .= " UNSIGNED";
        }

        if ($this->not_null) {
            $str .= " NOT NULL";
        } else {
            $str .= " NULL DEFAULT NULL";
        }

        if ($this->auto_increment) {
            $str .= " AUTO_INCREMENT";
        } elseif ($this->default !== null) {
            $str .= " DEFAULT {$this->default}";
        }

        if ($this->comment) {
            $str .= " COMMENT {$this->comment}";
        }

        if ($this->first) {
            $str .= " FIRST";
        } elseif ($this->after) {
            $str .= " AFTER `{$this->after}`";
        }

        return $str;
    }
}
