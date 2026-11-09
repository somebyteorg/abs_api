<?php

namespace App\Services;

use App\Models;

class WorkState
{
    public function read(int $id): array
    {
        $work = Models\Work::query()->select(['id', 'revision', 'sync_status', 'sync_error', 'synced_at', 'updated_at'])
            ->withCount(['chapters', 'media', 'chapters as playable_count' => fn ($q) => $q->whereHas('primaryMedia', fn ($m) => $m->whereHas('file'))])
            ->withSum('media', 'file_size')->withMax(['chapters as last_episode' => fn ($q) => $q->where('season_number', 1)], 'episode_number')->findOrFail($id);

        return [...$work->only(['id', 'revision', 'sync_status', 'sync_error', 'synced_at', 'updated_at']),
            'chapter_count' => $work->chapters_count, 'media_count' => $work->media_count, 'byte_size' => (int) $work->media_sum_file_size,
            'playable_count' => $work->playable_count, 'next_episode' => ((int) $work->last_episode) + 1,
            'publication_state' => $work->sync_status === 'pending' ? 'pending' : ($work->sync_status === 'error' ? 'error' : ($work->playable_count ? 'published' : 'empty'))];
    }
}
