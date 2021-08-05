<?php

namespace Bredala\Database;


interface DBInterface
{
    // -------------------------------------------------------------------------

    /**
     * Add hook
     *
     * @param string $hook
     * @param callable $callback
     * @return DBInterface
     */
    public function addHook(string $hook, callable $callback): DBInterface;

    /**
     * Execute hooks
     *
     * @param string $hook
     * @param array $params
     * @return DBInterface
     */
    public function execHook(string $hook, array $params = []): DBInterface;

    // -------------------------------------------------------------------------

    /**
     * Changes the current database
     *
     * @param string $database
     * @return DBInterface
     * @throws Exception
     */
    public function use(string $database): DBInterface;

    /**
     * Returns the last inserted id
     *
     * @return integer
     */
    public function getId(): int;

    /**
     * Escapes a string
     *
     * @param string $str
     * @return string
     */
    public function escape(string $str): string;

    // -------------------------------------------------------------------------

    /**
     * Start a transaction
     *
     * @return DBInterface
     * @throws Exception
     */
    public function transaction(): DBInterface;

    /**
     * Commit a transaction
     *
     * @return DBInterface
     * @throws Exception
     */
    public function commit(): DBInterface;

    /**
     * Rollback a transaction
     *
     * @return DBInterface
     * @throws Exception
     */
    public function rollback(): DBInterface;

    // -------------------------------------------------------------------------

    /**
     * Do not check foreign key constraints
     *
     * @return DBInterface
     */
    public function disableFkCheck(): DBInterface;

    /**
     * Check foreign key constraints
     *
     * @return DBInterface
     */
    public function enableFkCheck(): DBInterface;

    // -------------------------------------------------------------------------

    /**
     * Executes an SQL statement
     *
     * @param string $sql
     * @return DBInterface
     * @throws Exception
     */
    public function query(string $sql): DBInterface;

    /**
     * Prepares a statement for execution
     *
     * @param Query $statement
     * @return DBInterface
     * @throws Exception
     */
    public function prepare(string $statement): DBInterface;

    /**
     * Executes a SQL statement from a Query object
     *
     * @param QueryInterface $query
     * @return DBInterface
     * @throws Exception
     */
    public function exec(QueryInterface $query): DBInterface;

    // -------------------------------------------------------------------------

    /**
     * Bind params
     *
     * @param array $params
     * @return DBInterface
     * @throws Exception
     */
    public function bind(...$params): DBInterface;

    /**
     * Executes a prepared statement
     *
     * @param array $data
     * @return DBInterface
     * @throws Exception
     */
    public function execute(array $data = []): DBInterface;

    /**
     * Fetch all results
     *
     * @param bool|string $object Fetch as object
     * @return array
     */
    public function all();

    /**
     * Fetch next result
     *
     * @param bool|string $object Fetch as object
     * @return array|null
     */
    public function next();

    /**
     * Fetch first result
     * Return false if there is not exactly one result
     *
     * @param bool $object Fetch as object
     * @return array|null
     */
    public function one();

    /**
     * Number of rows
     *
     * @return int
     */
    public function count();

    // -------------------------------------------------------------------------

}
