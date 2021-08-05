<?php

namespace Bredala\Database;

/**
 * SessionHandler
 *
 * A Session handler using Bredala\Database
 *
 * SQL table sessions :
 * id char(40) NOT NULL,
 * ts int(10) UNSIGNED NOT NULL DEFAULT '0',
 * data text/blob NOT NULL
 */
class SessionHandler implements \SessionHandlerInterface
{

    /**
     * @var \Bredala\Database\DBInterface
     */
    private $driver;

    /**
     * @var string
     */
    private $table = 'sessions';

    /**
     * @var string
     */
    private $col_id;

    /**
     * @var string
     */
    private $col_data;

    /**
     * @var string
     */
    private $col_time;

    // -------------------------------------------------------------------------

    /**
     * @param DBInterface $driver
     * @param array $options
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

    /**
     * Open data
     *
     * @param string $save_path
     * @param string $name
     * @return bool
     */
    public function open($save_path, $name)
    {
        return $this->driver ? true : false;
    }

    // -------------------------------------------------------------------------

    /**
     * Read data
     *
     * @param string $session_id
     * @return string
     */
    public function read($session_id)
    {
        $query = QB::create()
            ->whereEq($this->col_id, $session_id)
            ->read($this->table);

        $row = $this->driver->exec($query)->one(false);
        return $row ? $row[$this->col_data] : '';
    }

    // -------------------------------------------------------------------------

    /**
     * Save all
     *
     * @param string $session_id
     * @param string $session_data
     * @return bool
     */
    public function write($session_id, $session_data)
    {
        $query = QB::create()
            ->add($this->col_id, $session_id)
            ->add($this->col_time, time())
            ->add($this->col_data, $session_data)
            ->replace($this->table);

        $this->driver->exec($query);

        return true;
    }

    // -------------------------------------------------------------------------

    /**
     * Destroy current session
     *
     * @param string $session_id
     * @return bool
     */
    public function destroy($session_id)
    {
        $query = QB::create()
            ->whereEq($this->col_id, $session_id)
            ->delete($this->table);

        $this->driver->exec($query);

        return true;
    }

    // -------------------------------------------------------------------------

    /**
     * Close
     *
     * @return bool
     */
    public function close()
    {
        return $this->driver ? true : false;
    }

    // -------------------------------------------------------------------------

    /**
     * Garbage collector
     *
     * @param int $maxlifetime
     * @return bool
     */
    public function gc($maxlifetime)
    {
        $query = QB::create()
            ->where($this->col_time . ' < ?', time() - $maxlifetime)
            ->delete($this->table);

        $this->driver->exec($query);

        return true;
    }

    // -------------------------------------------------------------------------
}

/* End of file */
