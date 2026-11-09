<?php

namespace App\Models\Abs;

class Podcast extends AbsModel
{
    protected $table = 'podcasts';

    protected function casts(): array
    {
        return ['tags' => 'array', 'genres' => 'array', 'explicit' => 'boolean', 'autodownloadepisodes' => 'boolean'];
    }
}
