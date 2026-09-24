<?php

namespace Bredala\Database;

/**
 * DB exception class
 *
 * The static methods are factories: they return the exception, the caller
 * still has to throw it.
 */
class Exception extends \Exception
{
    const int CONNECT = 1;
    const int PREPARE = 2;
    const int BIND = 3;
    const int EXECUTE = 4;
    const int TRANSACTION = 5;
    const int BUILD = 6;

    public static function connect(string $msg, ?\Throwable $prev = null): static
    {
        return new static($msg, self::CONNECT, $prev);
    }

    public static function prepare(string $msg, ?\Throwable $prev = null): static
    {
        return new static($msg, self::PREPARE, $prev);
    }

    public static function bind(string $msg, ?\Throwable $prev = null): static
    {
        return new static($msg, self::BIND, $prev);
    }

    public static function execute(string $msg, ?\Throwable $prev = null): static
    {
        return new static($msg, self::EXECUTE, $prev);
    }

    public static function transaction(string $msg, ?\Throwable $prev = null): static
    {
        return new static($msg, self::TRANSACTION, $prev);
    }

    public static function build(string $msg, ?\Throwable $prev = null): static
    {
        return new static($msg, self::BUILD, $prev);
    }
}

/* End of file */
