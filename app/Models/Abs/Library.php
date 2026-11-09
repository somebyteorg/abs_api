<?php

namespace App\Models\Abs;

class Library extends AbsModel
{
    protected $table = 'libraries';

    protected function casts(): array
    {
        return ['settings' => 'array', 'extradata' => 'array'];
    }
}
