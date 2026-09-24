<?php

namespace Bredala\Database;


interface DBInterface
{
    // -------------------------------------------------------------------------

    public function addHook(string $hook, callable $callback): DBInterface;

    public function execHook(string $hook, array $params = []): DBInterface;

    // -------------------------------------------------------------------------

    /**
     * Changes the current database
     *
     * @throws Exception
     */
    public function use(string $database): DBInterface;

    /**
     * Returns the last inserted id
     */
    public function getId(): int;

    /**
     * Escapes and quotes a string
     */
    public function escape(string $str): string;

    // -------------------------------------------------------------------------

    /**
     * @throws Exception
     */
    public function transaction(): DBInterface;

    /**
     * @throws Exception
     */
    public function commit(): DBInterface;

    /**
     * @throws Exception
     */
    public function rollback(): DBInterface;

    // -------------------------------------------------------------------------

    /**
     * Do not check foreign key constraints
     */
    public function disableFkCheck(): DBInterface;

    /**
     * Check foreign key constraints
     */
    public function enableFkCheck(): DBInterface;

    // -------------------------------------------------------------------------

    /**
     * Executes an SQL statement
     *
     * @throws Exception
     */
    public function query(string $sql): DBInterface;

    /**
     * Prepares a statement for execution
     *
     * @throws Exception
     */
    public function prepare(string $statement): DBInterface;

    /**
     * Prepares and executes a SQL statement from a Query object
     *
     * @throws Exception
     */
    public function exec(QueryInterface $query): DBInterface;

    // -------------------------------------------------------------------------

    /**
     * Binds a parameter to the prepared statement
     *
     * @throws Exception
     */
    public function bind(mixed ...$params): DBInterface;

    /**
     * Executes the prepared statement
     *
     * @throws Exception
     */
    public function execute(array $data = []): DBInterface;

    /**
     * Fetch all results
     */
    public function all(): array;

    /**
     * Fetch next result, null when there is none left
     */
    public function next(): ?array;

    /**
     * Fetch the first result, or null if there is not exactly one
     */
    public function one(): ?array;

    /**
     * Number of rows affected by the last statement
     */
    public function count(): int;

    // -------------------------------------------------------------------------

}
