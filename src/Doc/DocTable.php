<?php

namespace Bredala\Database\Doc;

class DocTable
{
    public string $name = '';
    public ?string $comment = null;

    /**
     * @var DocField[]
     */
    public array $fields = [];
}
