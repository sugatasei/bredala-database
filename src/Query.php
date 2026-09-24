<?php

namespace Bredala\Database;

class Query implements QueryInterface
{
    private string $statement;
    private array $data;

    public function __construct(string $statement = '', array $data = [])
    {
        $this->setStatement($statement);
        $this->setData($data);
    }

    public function setStatement(string $statement = ''): Query
    {
        $this->statement = rtrim(trim($statement), ";") . ";";
        return $this;
    }

    public function setData(array $data = []): Query
    {
        $this->data = $data;
        return $this;
    }

    public function getStatement(): string
    {
        return $this->statement;
    }

    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Inlines the data into the statement, without escaping: debug only
     */
    public function __toString(): string
    {
        $query = $this->statement;

        foreach ($this->data as $v) {
            $replace = is_string($v) ? "'" . $v . "'" : $v;
            $pos = strpos($query, '?');
            if ($pos !== false) {
                $query = substr_replace($query, $replace, $pos, 1);
            }
        }

        return $query;
    }
}
