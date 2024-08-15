<?php

namespace Bredala\Database\Doc;

class DocTable
{
    public $name;
    public $comment;

    /**
     * @var DocField[]
     */
    public $fields = [];
}
