<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StoredFile extends Base
{
    use SoftDeletes;

    protected $table = 'files';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = ['file_path', 'sha256', 'source_sha256'];

    /** Path relative to ABS_STORAGE_ROOT, shared by ABS and the reverse proxy. */
    public function absPath(): string
    {
        return $this->file_path;
    }

    public static function previewUrl(?string $fileId): ?string
    {
        return $fileId ? '/api/file/'.auth()->id().'/'.$fileId : null;
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'author_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'abs_list_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class, 'file_id')->withTrashed();
    }

    public static function reconcileAudio(array $fileIds): void
    {
        $fileIds = array_unique($fileIds);
        sort($fileIds);
        foreach ($fileIds as $id) {
            $file = self::withTrashed()->select(['id', 'deleted_at'])->where('file_type', 'audio')->lockForUpdate()->find($id);
            if (! $file) {
                continue;
            }
            $used = Media::query()->where('file_id', $id)->exists();
            if ($used && $file->trashed()) {
                $file->restore();
            } elseif (! $used && ! $file->trashed()) {
                $file->delete();
            }
        }
    }
}
