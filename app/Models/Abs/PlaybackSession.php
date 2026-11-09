<?php

namespace App\Models\Abs;

use App\Models;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaybackSession extends AbsModel
{
    protected $table = 'playbacksessions';

    protected function casts(): array
    {
        return ['timelistening' => 'float', 'currenttime' => 'float', 'duration' => 'float', 'updatedat' => 'datetime', 'extradata' => 'array'];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class, 'mediaitemid');
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(PodcastEpisode::class, 'mediaitemid');
    }

    public function scopeForAudience(Builder $query, Models\User $viewer, string $type): void
    {
        $query->where('timelistening', '>', 0);
        if ($type !== 'all') {
            $query->where('mediaitemtype', $type === 'book' ? 'book' : 'podcastEpisode');
        }
        if (! $viewer->adult_mode) {
            $query->where(fn ($metadata) => $metadata->whereNull('mediametadata->explicit')->orWhere('mediametadata->explicit', false))
                ->whereDoesntHave('book', fn ($book) => $book->where('explicit', true))
                ->whereDoesntHave('episode.podcast', fn ($podcast) => $podcast->where('explicit', true));
        }
    }
}
