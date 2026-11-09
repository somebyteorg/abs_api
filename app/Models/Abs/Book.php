<?php

namespace App\Models\Abs;

class Book extends AbsModel
{
    protected $table = 'books';

    protected function casts(): array
    {
        return ['audiofiles' => 'array', 'chapters' => 'array', 'narrators' => 'array', 'tags' => 'array', 'genres' => 'array', 'explicit' => 'boolean', 'abridged' => 'boolean'];
    }
}
