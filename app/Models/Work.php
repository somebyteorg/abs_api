<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Work extends Base
{
    use SoftDeletes;

    protected $table = 'abs_lists';

    protected $hidden = ['abs_id', 'abs_media_id', 'sync_enqueued_at'];

    protected $appends = ['cover_url'];

    public const CARD_FIELDS = ['id', 'user_id', 'abs_library_id', 'type', 'name', 'tagline', 'cover_file_id', 'date_air', 'is_adult', 'updated_at'];

    public const PUBLIC_FIELDS = ['id', 'user_id', 'abs_library_id', 'type', 'name', 'tagline', 'description', 'cover_file_id', 'date_air', 'is_adult', 'sync_status', 'sync_error', 'synced_at', 'created_at', 'updated_at', 'revision'];

    public const FIELDS = [...self::PUBLIC_FIELDS, 'abs_id', 'abs_media_id', 'need_sync', 'sync_enqueued_at', 'deleted_at'];

    protected function casts(): array
    {
        return ['date_air' => 'date:Y-m-d', 'is_adult' => 'boolean', 'revision' => 'integer', 'need_sync' => 'boolean', 'synced_at' => 'datetime'];
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->adult_mode) {
            $query->where('is_adult', false);
        }
    }

    public function scopeMatching(Builder $query, string $term): void
    {
        $query->where(fn ($names) => $names->whereLike('name', '%'.$term.'%')->orWhereLike('tagline', '%'.$term.'%'));
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class, 'abs_library_id');
    }

    public function coverFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'cover_file_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function authors(): BelongsToMany
    {
        return $this->belongsToMany(Author::class, 'abs_work_authors', 'work_id', 'author_id')->withPivot('abs_id')->withTimestamps()->orderBy('name');
    }

    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class, 'abs_list_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class, 'abs_list_id');
    }

    public function getCoverUrlAttribute(): ?string
    {
        return StoredFile::previewUrl($this->cover_file_id);
    }
}
