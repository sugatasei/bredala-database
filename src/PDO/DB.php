<?php

namespace Bredala\Database\PDO;

use Bredala\Database\DBInterface;
use Bredala\Database\Exception;
use Bredala\Database\QueryInterface;

class DB implements DBInterface
{
    const HOOK_BEFORE_QUERY = 'before_query';
    const HOOK_AFTER_QUERY = 'after_query';

    /**
     * @var \PDO
     */
    private $pdo;

    /**
     * @var \PDOStatement
     */
    private $stmt;

    /**
     * @var array
     */
    private $hooks = [];

    // -------------------------------------------------------------------------

    /**
     * @param \PDO $pdo
     */
    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param \PDO $pdo
     * @return $this
     */
    public static function create(\PDO $pdo)
    {
        return new static($pdo);
    }

    // -------------------------------------------------------------------------

    /**
     * @param string $hook
     * @param callable $callback
     * @return DBInterface
     */
    public function addHook(string $hook, callable $callback): DBInterface
    {
        $this->hooks[$hook][] = $callback;
        return $this;
    }

    /**
     * @param string $hook
     * @param array $params
     * @return DBInterface
     */
    public function execHook(string $hook, array $params = []): DBInterface
    {
        foreach ($this->hooks[$hook] ?? [] as $callback) {
            call_user_func_array($callback, $params);
        }

        return $this;
    }

    // -------------------------------------------------------------------------

    /**
     * Changes the current database
     *
     * @param string $database
     * @return DBInterface
     * @throws Exception
     */
    public function use(string $database): DBInterface
    {
        try {
            $this->pdo->exec("USE {$database}");
        } catch (\PDOException $ex) {
            throw Exception::connect(__METHOD__, $ex);
        }

        return $this;
    }



    /**
     * Returns the last inserted id
     *
     * @return integer
     */
    public function getId(): int
    {
        return $this->pdo->lastInsertId() ?: 0;
    }

    /**
     * Escapes a string
     *
     * @param string $str
     * @return string
     */
    public function escape(string $str): string
    {
        return $this->pdo->quote($str);
    }

    // -------------------------------------------------------------------------

    /**
     * Start a transaction
     *
     * @return DBInterface
     * @throws Exception
     */
    public function transaction(): DBInterface
    {
        try {
            $this->pdo->beginTransaction();
        } catch (\PDOException $ex) {
            throw Exception::transaction(__METHOD__, $ex);
        }

        return $this;
    }

    /**
     * Commit a transaction
     *
     * @return DBInterface
     * @throws Exception
     */
    public function commit(): DBInterface
    {
        try {
            $this->pdo->commit();
        } catch (\PDOException $ex) {
            throw Exception::transaction(__METHOD__, $ex);
        }

        return $this;
    }

    /**
     * Rollback a transaction
     *
     * @return DBInterface
     * @throws Exception
     */
    public function rollback(): DBInterface
    {
        try {
            $this->pdo->rollBack();
        } catch (\PDOException $ex) {
            throw Exception::transaction(__METHOD__, $ex);
        }

        return $this;
    }

    // -------------------------------------------------------------------------

    /**
     * Do not check foreign key constraints
     *
     * @return DBInterface
     */
    public function disableFkCheck(): DBInterface
    {
        return $this->query("SET FOREIGN_KEY_CHECKS=0;");
    }

    /**
     * Check foreign key constraints
     *
     * @return DBInterface
     */
    public function enableFkCheck(): DBInterface
    {
        return $this->query("SET FOREIGN_KEY_CHECKS=1;");
    }

    // -------------------------------------------------------------------------

    /**
     * Executes an SQL statement
     *
     * @param string $sql
     * @return DBInterface
     * @throws Exception
     */
    public function query(string $sql): DBInterface
    {
        try {
            $this->execHook(static::HOOK_BEFORE_QUERY);
            $this->stmt = $this->pdo->query($sql);
            $this->execHook(static::HOOK_AFTER_QUERY);
        } catch (\PDOException $ex) {
            throw Exception::execute(__METHOD__, $ex);
        }

        return $this;
    }

    /**
     * Prepares a statement for execution
     *
     * @param string $statement
     * @return DBInterface
     * @throws Exception
     */
    public function prepare(string $statement): DBInterface
    {
        try {
            $this->execHook(static::HOOK_BEFORE_QUERY);
            $this->stmt = $this->pdo->prepare($statement);
        } catch (\PDOException $ex) {
            throw Exception::prepare(__METHOD__, $ex);
        }

        return $this;
    }

    /**
     * Executes a SQL statement from a Query object
     *
     * @param QueryInterface $query
     * @return DBInterface
     * @throws Exception
     */
    public function exec(QueryInterface $query): DBInterface
    {
        return $this
            ->prepare($query->getStatement())
            ->execute($query->getData());
    }

    // -------------------------------------------------------------------------
    // Statements
    // -------------------------------------------------------------------------

    public function bind(...$args): DBInterface
    {
        if (!$this->stmt) {
            return $this;
        }

        $this->stmt->bindParam(...$args);
        return $this;
    }

    /**
     * Executes a prepared statement
     *
     * @param array $data
     * @return DBInterface
     * @throws Exception
     */
    public function execute(array $data = []): DBInterface
    {
        if (!$this->stmt) {
            return $this;
        }

        try {
            $this->stmt->execute($data ?: null);
            $this->execHook(static::HOOK_AFTER_QUERY);
        } catch (\PDOException $ex) {
            throw Exception::execute(__METHOD__, $ex);
        }

        return $this;
    }

    /**
     * Fetch all results
     *
     * @return array
     */
    public function all()
    {
        if (!$this->stmt) {
            return [];
        }

        return $this->stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Fetch next result
     *
     * @param bool|string $object Fetch as object
     * @return array|null
     */
    public function next()
    {
        if (!$this->stmt) {
            return null;
        }

        return $this->stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Fetch first result
     * Return false if there is not exactly one result
     *
     * @return array|null
     */
    public function one()
    {
        if (!$this->stmt || $this->stmt->rowCount() !== 1) {
            return null;
        }

        return $this->stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Number of rows
     *
     * @return int
     */
    public function count()
    {
        if (!$this->stmt) {
            return 0;
        }

        return $this->stmt->rowCount();
    }

    // -------------------------------------------------------------------------
}
