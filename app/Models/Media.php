<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Media extends Base
{
    use SoftDeletes;

    protected $table = 'abs_media';

    public const FIELDS = ['id', 'file_id', 'abs_list_id', 'abs_item_id', 'user_id', 'name', 'file_size', 'file_second', 'created_at', 'is_reference'];

    protected function casts(): array
    {
        return ['file_metadata' => 'array', 'file_second' => 'float', 'file_size' => 'integer', 'is_reference' => 'boolean'];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'abs_list_id');
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class, 'abs_item_id')->withTrashed();
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'file_id');
    }
}
