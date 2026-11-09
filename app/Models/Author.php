<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Author extends Base
{
    use SoftDeletes;

    protected $table = 'abs_authors';

    protected $appends = ['cover_url'];

    public const FIELDS = ['id', 'user_id', 'name', 'description', 'cover_file_id', 'updated_at'];

    public function coverFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'cover_file_id');
    }

    public function works(): BelongsToMany
    {
        return $this->belongsToMany(Work::class, 'abs_work_authors', 'author_id', 'work_id')->withPivot('abs_id')->withTimestamps();
    }

    public function getCoverUrlAttribute(): ?string
    {
        return StoredFile::previewUrl($this->cover_file_id);
    }
}
