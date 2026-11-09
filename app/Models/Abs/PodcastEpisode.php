<?php

namespace App\Models\Abs;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PodcastEpisode extends AbsModel
{
    protected $table = 'podcastepisodes';

    protected function casts(): array
    {
        return ['audiofile' => 'array', 'chapters' => 'array', 'extradata' => 'array', 'publishedat' => 'datetime'];
    }

    public function podcast(): BelongsTo
    {
        return $this->belongsTo(Podcast::class, 'podcastid');
    }
}
