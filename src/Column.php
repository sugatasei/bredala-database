<?php

namespace Bredala\Database;

/**
 * Column
 */
class Column
{
    protected $name;
    protected $type = null;
    protected $unsigned = false;
    protected $auto_increment = false;
    protected $not_null = false;
    protected $default = null;
    protected $after = null;
    protected $first = false;
    protected $comment = null;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->varchar();
    }

    /**
     * Set type
     *
     * @param string $type
     * @param mixed $constraints
     * @return $this
     */
    public function type(string $type, ...$constraints)
    {
        $this->type = mb_strtoupper($type);
        if ($constraints) {
            $this->type .= '(' . implode(',', $constraints) . ')';
        }

        return $this;
    }

    /**
     * TYPE : BOOL
     *
     * @param boolean $default
     * @return $this
     */
    public function bool(bool $default = false)
    {
        return $this->type('tinyint', 1)->unsigned()->defaultValue($default ? 1 : 0);
    }

    /**
     * Type : INT
     *
     * @param string $prefix : TINY SMALL MEDIUM BIG
     * @return $this
     */
    public function int($prefix = '')
    {
        return $this->type($prefix . 'int');
    }

    /**
     * Type : FLOAT
     *
     * @return $this
     */
    public function float()
    {
        return $this->type('float');
    }

    /**
     * Type : DECIMAL
     *
     * @param integer $precision
     * @param integer $scale
     * @return $this
     */
    public function decimal(int $precision = 10, int $scale = 2)
    {
        return $this->type('decimal', $precision, $scale);
    }

    /**
     * Type : CHAR
     *
     * @param integer $len
     * @return $this
     */
    public function char(int $len)
    {
        return $this->type('char', $len);
    }

    /**
     * Type : VARCHAR
     *
     * @param integer $len
     * @return $this
     */
    public function varchar(int $len = 255)
    {
        return $this->type('varchar', $len);
    }

    /**
     * Type : TEXT
     *
     * @param string $prefix : TINY, MEDIUM, LONG
     * @return $this
     */
    public function text($prefix = '')
    {
        return $this->type($prefix . 'text');
    }

    /**
     * Type : BLOB
     *
     * @param string $prefix : TINY, MEDIUM, LONG
     * @return $this
     */
    public function blob($prefix = '')
    {
        return $this->type($prefix . 'blob');
    }

    /**
     * Type : TIMESTAMP
     *
     * @return $this
     */
    public function timestamp()
    {
        return $this->type('timestamp');
    }

    /**
     * Type : DATETIME
     *
     * @return $this
     */
    public function datetime()
    {
        return $this->type('datetime');
    }

    /**
     * Type : DATE
     *
     * @return $this
     */
    public function date()
    {
        return $this->type('date');
    }

    /**
     * Type : TIME
     *
     * @return $this
     */
    public function time()
    {
        return $this->type('time');
    }

    /**
     * Set unsigned
     *
     * @param bool $value
     * @return $this
     */
    public function unsigned(bool $value = true)
    {
        $this->unsigned = $value;
        return $this;
    }

    /**
     * Force not null
     *
     * @param boolean $value
     * @return $this
     */
    public function notNull()
    {
        $this->not_null = true;
        return $this;
    }

    /**
     * Set default value
     *
     * @param mixed $value
     * @param boolean $quote
     * @return $this
     */
    public function defaultValue($value, bool $quote = true)
    {
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
     * @param boolean $on_update : add ON UPDATE CURRENT_TIMESTAMP
     * @return $this
     */
    public function defaultTimestamp($on_update = false)
    {
        $command = 'CURRENT_TIMESTAMP';
        if ($on_update) $command .= '  ON UPDATE CURRENT_TIMESTAMP';
        return $this->defaultValue($command, false);
    }

    /**
     * Add AUTO_INCREMENT
     *
     * @param bool $value
     * @return $this
     */
    public function autoIncrement(bool $value = true)
    {
        $this->auto_increment = $value;
        return $this->notNull();
    }

    /**
     * Add COMMENT
     *
     * @param string $value
     * @return $this
     */
    public function comment(string $value)
    {
        $this->comment = FB::quote($value);
        return $this;
    }

    /**
     * Add first
     */
    public function first()
    {
        $this->first = true;
        return $this;
    }

    /**
     * Add after
     *
     * @param string $name
     * @return $this
     */
    public function after(string $name)
    {
        $this->after = $name;
        return $this;
    }

    /**
     * @return string
     */
    public function __toString()
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
