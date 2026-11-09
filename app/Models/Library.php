<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Library extends Base
{
    use SoftDeletes;

    protected $table = 'abs_libraries';

    protected $hidden = ['abs_id', 'abs_folder_id', 'need_sync', 'sync_enqueued_at'];

    public const FIELDS = ['id', 'name', 'type', 'abs_id', 'abs_folder_id', 'need_sync', 'sync_enqueued_at', 'deleted_at'];

    public function works(): HasMany
    {
        return $this->hasMany(Work::class, 'abs_library_id');
    }
}
