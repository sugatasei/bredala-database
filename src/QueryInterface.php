<?php

namespace Bredala\Database;

interface QueryInterface
{
    public function getStatement(): string;

    public function getData(): array;
}
