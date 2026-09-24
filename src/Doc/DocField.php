<?php

namespace Bredala\Database\Doc;

class DocField
{
    public string $name = '';
    public string $idx = '';
    public string $type = '';
    public string $ref = '';
    public ?string $value = null;
    public ?string $comment = null;
}
