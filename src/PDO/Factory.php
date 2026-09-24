<?php

namespace Bredala\Database\PDO;

use Bredala\Database\Exception;

class Factory
{
    /**
     * @throws Exception
     */
    public static function create(string $dsn, ?string $username = null, ?string $password = null, array $options = []): \PDO
    {
        // Default options
        $options[\PDO::ATTR_ERRMODE] = $options[\PDO::ATTR_ERRMODE] ?? \PDO::ERRMODE_EXCEPTION;
        $options[\PDO::ATTR_EMULATE_PREPARES] = $options[\PDO::ATTR_EMULATE_PREPARES] ?? false;
        $options[\PDO::ATTR_STRINGIFY_FETCHES] = $options[\PDO::ATTR_STRINGIFY_FETCHES] ?? false;

        try {
            return new \PDO($dsn, $username, $password, $options);
        } catch (\PDOException $ex) {
            throw new Exception("Connection ({$dsn})", Exception::CONNECT, $ex);
        }
    }

    // -------------------------------------------------------------------------
}

/* End of file */
