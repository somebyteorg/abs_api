<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Base
{
    public $timestamps = false;

    public const DEFAULT_CATEGORY = 'without_uploads';

    public const CATEGORY_ACTIONS = [
        'upload' => ['audio.uploaded', 'audio.referenced', 'cover.uploaded', 'author.cover_uploaded'],
        'create' => ['work.created', 'chapter.created', 'author.created', 'library.created'],
        'update' => ['work.updated', 'chapter.updated', 'chapters.edited', 'author.updated', 'library.updated', 'profile.updated', 'role.updated'],
        'organize' => ['primary.changed', 'chapters.reordered', 'chapters.merged', 'resources.moved'],
        'delete' => ['work.deleted', 'chapter.deleted', 'author.deleted', 'library.deleted', 'media.deleted'],
        'restore' => ['activity.restored'],
        'other' => ['notice.accepted', 'sync.requested'],
    ];

    public function scopeCategory(Builder $query, string $category): void
    {
        if ($category === self::DEFAULT_CATEGORY) {
            $query->whereNotIn('action', self::CATEGORY_ACTIONS['upload']);
        } elseif (isset(self::CATEGORY_ACTIONS[$category])) {
            $query->whereIn('action', self::CATEGORY_ACTIONS[$category]);
        }
    }

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
