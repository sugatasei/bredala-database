<?php

namespace Bredala\Database;

/**
 * A Session handler using Bredala\Database
 *
 * SQL table sessions :
 * id char(40) NOT NULL,
 * ts int(10) UNSIGNED NOT NULL DEFAULT '0',
 * data text/blob NOT NULL
 */
class SessionHandler implements \SessionHandlerInterface
{
    private DBInterface $driver;
    private string $table;
    private string $col_id;
    private string $col_data;
    private string $col_time;

    // -------------------------------------------------------------------------

    /**
     * @param array{table?: string, id?: string, time?: string, data?: string} $options
     *        table and column names, defaulting to sessions, id, ts and data
     */
    public function __construct(DBInterface $driver, array $options = [])
    {
        $this->driver = $driver;
        $this->table = $options['table'] ?? 'sessions';
        $this->col_id = $options['id'] ?? 'id';
        $this->col_time = $options['time'] ?? 'ts';
        $this->col_data = $options['data'] ?? 'data';
    }

    // -------------------------------------------------------------------------

    public function open(string $path, string $name): bool
    {
        return true;
    }

    // -------------------------------------------------------------------------

    public function read(string $id): string|false
    {
        $query = QB::create($this->table)
            ->whereEq($this->col_id, $id)
            ->read();

        $row = $this->driver->exec($query)->one();
        return $row ? $row[$this->col_data] : '';
    }

    // -------------------------------------------------------------------------

    public function write(string $id, string $data): bool
    {
        $query = QB::create($this->table)
            ->add($this->col_id, $id)
            ->add($this->col_time, time())
            ->add($this->col_data, $data)
            ->replace();

        $this->driver->exec($query);

        return true;
    }

    // -------------------------------------------------------------------------

    public function destroy(string $id): bool
    {
        $query = QB::create($this->table)
            ->whereEq($this->col_id, $id)
            ->delete();

        $this->driver->exec($query);

        return true;
    }

    // -------------------------------------------------------------------------

    public function close(): bool
    {
        return true;
    }

    // -------------------------------------------------------------------------

    /**
     * Deletes the expired sessions and returns how many were deleted
     */
    public function gc(int $max_lifetime): int|false
    {
        $query = QB::create($this->table)
            ->where($this->col_time . ' < ?', time() - $max_lifetime)
            ->delete();

        return $this->driver->exec($query)->count();
    }

    // -------------------------------------------------------------------------
}

/* End of file */
