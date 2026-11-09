<?php

namespace App\Models;

class AuthorLibrary extends Base
{
    protected $table = 'abs_author_libraries';

    public $incrementing = false;

    protected $keyType = 'string';
}
