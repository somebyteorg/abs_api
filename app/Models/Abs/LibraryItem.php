<?php

namespace App\Models\Abs;

class LibraryItem extends AbsModel
{
    protected $table = 'libraryitems';

    protected function casts(): array
    {
        return ['mtime' => 'datetime', 'ctime' => 'datetime', 'birthtime' => 'datetime', 'libraryfiles' => 'array', 'extradata' => 'array', 'isfile' => 'boolean', 'ismissing' => 'boolean', 'isinvalid' => 'boolean'];
    }
}
