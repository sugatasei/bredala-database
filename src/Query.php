<?php

namespace Bredala\Database;

/**
 * Query
 */
class Query implements QueryInterface
{
    private $statement;
    private $data;

    /**
     * @param string $statement
     * @param array $data
     */
    public function __construct(string $statement = '', array $data = [])
    {
        $this->setStatement($statement);
        $this->setData($data);
    }

    /**
     * @param string $statement
     * @return $this
     */
    public function setStatement(string $statement = ''): Query
    {
        $this->statement = rtrim(trim($statement), ";") . ";";
        return $this;
    }

    /**
     * @param array $data
     * @return Query
     */
    public function setData(array $data = []): Query
    {
        $this->data = $data;
        return $this;
    }

    /**
     * @return string
     */
    public function getStatement(): string
    {
        return $this->statement;
    }

    /**
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function __toString()
    {
        $query = $this->statement;

        foreach ($this->data as $k => $v) {
            $replace = is_string($v) ? "'" . $v . "'" : $v;
            $pos = strpos($query, '?');
            if ($pos !== false) {
                $query = substr_replace($query, $replace, $pos, 1);
            }
        }

        return $query;
    }
}
