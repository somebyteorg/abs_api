<?php

namespace App\Models\Abs;

class PodcastEpisode extends AbsModel
{
    protected $table = 'podcastepisodes';

    protected function casts(): array
    {
        return ['audiofile' => 'array', 'chapters' => 'array', 'extradata' => 'array', 'publishedat' => 'datetime'];
    }
}
