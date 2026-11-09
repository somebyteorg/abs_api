<?php

namespace App\Services;

use App\Models;
use Illuminate\Support\Str;

class Chapters
{
    public function work(int $id, ?int $revision = null): Models\Work
    {
        $work = Models\Work::query()->select(Models\Work::FIELDS)->lockForUpdate()->findOrFail($id);
        if ($revision !== null) {
            abort_unless($work->revision === $revision, 409, '作品有新改动，请刷新后再操作。');
        }

        return $work;
    }

    public function find(int $workId, int $chapterId): Models\Chapter
    {
        return Models\Chapter::query()->select(Models\Chapter::FIELDS)->where('abs_list_id', $workId)->findOrFail($chapterId);
    }

    public function create(Models\Work $work, Models\User $user, array $data, ?int $index = null): Models\Chapter
    {
        abort_if(Models\Chapter::query()->where('abs_list_id', $work->id)->where('season_number', $data['season_number'])->where('episode_number', $data['episode_number'])->exists(), 409, '这个季／集编号已经被其他章节使用。');

        return Models\Chapter::create([
            'user_id' => $user->id, 'abs_list_id' => $work->id, 'title' => $data['title'],
            'season_number' => $data['season_number'], 'episode_number' => $data['episode_number'],
            'index' => $index ?? ((int) Models\Chapter::query()->where('abs_list_id', $work->id)->max('index')) + 1,
            'abs_id' => (string) Str::uuid(), 'primary_media_id' => null,
        ]);
    }

    /** Exactly one live resource is unambiguous; several resources require an explicit, valid choice. */
    public function primaryAfterRemoval(Models\Chapter $chapter, ?int $removedId = null, ?int $replacement = null): ?int
    {
        $ids = Models\Media::query()->where('abs_item_id', $chapter->id)->when($removedId, fn ($q, $id) => $q->whereKeyNot($id))->pluck('id')->all();
        if (! $ids) {
            return null;
        }
        if (count($ids) === 1) {
            return $ids[0];
        }
        if ($replacement !== null) {
            abort_unless(in_array($replacement, $ids, true), 422, '新的主资源必须属于这个章节。');

            return $replacement;
        }
        if (in_array($chapter->primary_media_id, $ids, true)) {
            return $chapter->primary_media_id;
        }
        abort(422, '章节还有多份资源，请先指定新的主资源。');
    }
}
