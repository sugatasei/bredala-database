<?php

namespace Bredala\Database;

/**
 * QueryInterface
 */
interface QueryInterface
{
    /**
     * @return string
     */
    public function getStatement(): string;

    /**
     * @return array
     */
    public function getData(): array;
}
