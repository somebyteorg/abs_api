<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Chapter extends Base
{
    use SoftDeletes;

    protected $table = 'abs_items';

    protected $hidden = ['abs_id', 'slot_active', 'merged_into_id'];

    public const PAGE_SIZE = 20;

    public const FIELDS = ['id', 'user_id', 'abs_list_id', 'season_number', 'episode_number', 'index', 'title', 'abs_id', 'primary_media_id', 'updated_at'];

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy($query->qualifyColumn('index'))->orderBy($query->qualifyColumn('id'));
    }

    public function scopeByEpisode(Builder $query): void
    {
        $query->orderBy('season_number')->orderBy('episode_number')->orderBy('id');
    }

    public function scopeWithOrderPositions(Builder $query): void
    {
        $query->selectRaw('ROW_NUMBER() OVER (ORDER BY "index", "id") AS listening_position')
            ->selectRaw('ROW_NUMBER() OVER (ORDER BY "season_number", "episode_number", "id") AS episode_position');
    }

    public function scopeWithResources(Builder $query): void
    {
        $query->with(['resources' => fn ($resources) => $resources->select(Media::FIELDS)->with('uploader:id,username,pseudonym')]);
    }

    public function scopeMatching(Builder $query, ?string $term): void
    {
        if (! $term) {
            return;
        }
        $query->where(function (Builder $query) use ($term) {
            $query->whereLike('title', '%'.$term.'%')
                ->orWhereHas('resources', fn ($resources) => $resources->where(fn ($resources) => $resources
                    ->whereLike('name', '%'.$term.'%')
                    ->orWhereHas('uploader', fn ($users) => $users->named($term))));
            if (preg_match('/^s(\d+)e(\d+)$/i', trim($term), $match)) {
                $query->orWhere(fn ($chapter) => $chapter->where('season_number', (int) $match[1])->where('episode_number', (int) $match[2]));
            }
        });
    }

    public function scopeResourceState(Builder $query, string $state): void
    {
        match ($state) {
            'missing' => $query->doesntHave('resources'),
            'multiple' => $query->has('resources', '>', 1),
            'active' => $query->whereHas('primaryMedia', fn ($media) => $media->whereHas('file')),
            default => null,
        };
    }

    protected function casts(): array
    {
        return ['primary_media_id' => 'integer', 'season_number' => 'integer', 'episode_number' => 'integer', 'listening_position' => 'integer'];
    }

    public function resources(): HasMany
    {
        return $this->hasMany(Media::class, 'abs_item_id');
    }

    public function primaryMedia(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'primary_media_id');
    }
}
