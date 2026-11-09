<?php

namespace App\Models;

class InboxMessage extends Base
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
