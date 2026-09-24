<?php

namespace Bredala\Database\PDO;

use Bredala\Database\DBInterface;
use Bredala\Database\Exception;
use Bredala\Database\QueryInterface;

class DB implements DBInterface
{
    const string HOOK_BEFORE_QUERY = 'before_query';
    const string HOOK_AFTER_QUERY = 'after_query';

    private \PDO $pdo;
    private ?\PDOStatement $stmt = null;

    /**
     * @var array<string, callable[]>
     */
    private array $hooks = [];

    // -------------------------------------------------------------------------

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public static function create(\PDO $pdo): static
    {
        return new static($pdo);
    }

    // -------------------------------------------------------------------------

    public function addHook(string $hook, callable $callback): DBInterface
    {
        $this->hooks[$hook][] = $callback;
        return $this;
    }

    public function execHook(string $hook, array $params = []): DBInterface
    {
        foreach ($this->hooks[$hook] ?? [] as $callback) {
            call_user_func_array($callback, $params);
        }

        return $this;
    }

    // -------------------------------------------------------------------------

    public function use(string $database): DBInterface
    {
        try {
            $this->pdo->exec("USE {$database}");
        } catch (\PDOException $ex) {
            throw Exception::connect(__METHOD__, $ex);
        }

        return $this;
    }

    public function getId(): int
    {
        return $this->pdo->lastInsertId() ?: 0;
    }

    public function escape(string $str): string
    {
        return $this->pdo->quote($str);
    }

    // -------------------------------------------------------------------------

    public function transaction(): DBInterface
    {
        try {
            $this->pdo->beginTransaction();
        } catch (\PDOException $ex) {
            throw Exception::transaction(__METHOD__, $ex);
        }

        return $this;
    }

    public function commit(): DBInterface
    {
        try {
            $this->pdo->commit();
        } catch (\PDOException $ex) {
            throw Exception::transaction(__METHOD__, $ex);
        }

        return $this;
    }

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

    public function disableFkCheck(): DBInterface
    {
        return $this->query("SET FOREIGN_KEY_CHECKS=0;");
    }

    public function enableFkCheck(): DBInterface
    {
        return $this->query("SET FOREIGN_KEY_CHECKS=1;");
    }

    // -------------------------------------------------------------------------

    public function query(string $sql): DBInterface
    {
        try {
            $this->execHook(static::HOOK_BEFORE_QUERY);
            $this->stmt = $this->pdo->query($sql) ?: null;
            $this->execHook(static::HOOK_AFTER_QUERY);
        } catch (\PDOException $ex) {
            throw Exception::execute(__METHOD__, $ex);
        }

        return $this;
    }

    public function prepare(string $statement): DBInterface
    {
        try {
            $this->execHook(static::HOOK_BEFORE_QUERY);
            $this->stmt = $this->pdo->prepare($statement) ?: null;
        } catch (\PDOException $ex) {
            throw Exception::prepare(__METHOD__, $ex);
        }

        return $this;
    }

    public function exec(QueryInterface $query): DBInterface
    {
        return $this
            ->prepare($query->getStatement())
            ->execute($query->getData());
    }

    // -------------------------------------------------------------------------
    // Statements
    // -------------------------------------------------------------------------

    public function bind(mixed ...$args): DBInterface
    {
        if (!$this->stmt) {
            return $this;
        }

        $this->stmt->bindParam(...$args);
        return $this;
    }

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

    public function all(): array
    {
        if (!$this->stmt) {
            return [];
        }

        return $this->stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function next(): ?array
    {
        if (!$this->stmt) {
            return null;
        }

        return $this->stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Fetch the first result, or null if there is not exactly one
     *
     * The "exactly one" check cannot use rowCount(): PDO only guarantees it for
     * statements that modify rows, and on a SELECT the SQLite driver reports 0,
     * which used to make this method return null for every query. It is done by
     * probing for a second row instead, which every driver supports.
     */
    public function one(): ?array
    {
        if (!$this->stmt) {
            return null;
        }

        $row = $this->stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->stmt->fetch(\PDO::FETCH_ASSOC) === false ? $row : null;
    }

    /**
     * Number of rows affected by the last statement
     *
     * This is PDOStatement::rowCount(), so it is only meaningful after an
     * INSERT, UPDATE, DELETE or REPLACE. On a SELECT its value is driver
     * dependent — SQLite reports 0 — so count the rows in SQL instead, with
     * QB::count(), rather than reading it here.
     */
    public function count(): int
    {
        if (!$this->stmt) {
            return 0;
        }

        return $this->stmt->rowCount();
    }

    // -------------------------------------------------------------------------
}
