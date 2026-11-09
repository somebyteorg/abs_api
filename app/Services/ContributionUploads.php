<?php

namespace App\Services;

use App\Models;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ContributionUploads
{
    public function page(Models\User $user, bool $mine): LengthAwarePaginator
    {
        $audio = Models\Media::withTrashed()->select(['created_at'])->selectRaw("'audio' AS kind, CAST(id AS TEXT) AS entry_id")
            ->when($mine, fn ($q) => $q->where('user_id', $user->id));
        $covers = Models\StoredFile::withTrashed()->select(['created_at'])->selectRaw("'cover' AS kind, CAST(id AS TEXT) AS entry_id")->where('file_type', 'cover')
            ->when($mine, fn ($q) => $q->where('user_id', $user->id));
        $page = DB::query()->fromSub($audio->toBase()->unionAll($covers->toBase()), 'contributions')->orderByDesc('created_at')->orderBy('kind')->orderBy('entry_id')->paginate(30);
        $rows = collect($page->items());
        $media = Models\Media::withTrashed()->select([...Models\Media::FIELDS, 'deleted_at', 'delete_reason'])
            ->whereIn('id', $rows->where('kind', 'audio')->pluck('entry_id'))
            ->with(['uploader:id,username,pseudonym', 'work' => fn ($q) => $q->withTrashed()->select(['id', 'name', 'tagline', 'type', 'cover_file_id', 'date_air', 'deleted_at']), 'chapter' => fn ($q) => $q->withTrashed()->select(['id', 'title', 'season_number', 'episode_number', 'primary_media_id', 'deleted_at'])])->get()->keyBy('id');
        $files = Models\StoredFile::withTrashed()->select(['id', 'file_name', 'file_size', 'abs_list_id', 'author_id', 'created_at', 'deleted_at', 'user_id'])
            ->whereIn('id', $rows->where('kind', 'cover')->pluck('entry_id'))
            ->with(['uploader:id,username,pseudonym', 'work:id,name,tagline,type,cover_file_id,date_air', 'author:id,name,cover_file_id'])->get()->keyBy('id');
        $page->setCollection($rows->map(fn ($row) => $row->kind === 'audio'
            ? $this->audio($media->get($row->entry_id))
            : $this->cover($files->get($row->entry_id))));

        return $page;
    }

    private function audio(Models\Media $entry): array
    {
        return [
            'id' => 'audio-'.$entry->id,
            'file_id' => $entry->file_id,
            'file_type' => 'audio',
            'abs_list_id' => $entry->abs_list_id,
            'name' => $entry->name,
            'file_size' => $entry->file_size,
            'file_second' => $entry->file_second,
            'is_reference' => $entry->is_reference,
            'created_at' => $entry->created_at,
            'deleted_at' => $entry->deleted_at,
            'delete_reason' => $entry->delete_reason,
            'uploader' => $entry->uploader,
            'work' => $entry->work,
            'chapter' => $entry->chapter,
            'is_primary' => $entry->chapter?->primary_media_id === $entry->id,
            'is_current' => ! $entry->trashed(),
        ];
    }

    private function cover(Models\StoredFile $file): array
    {
        $current = $file->author_id
            ? $file->author?->cover_file_id === $file->id
            : ($file->abs_list_id && $file->work?->cover_file_id === $file->id);

        return [
            'id' => 'cover-'.$file->id,
            'file_id' => $file->id,
            'file_type' => 'cover',
            'abs_list_id' => $file->abs_list_id,
            'name' => $file->file_name,
            'file_size' => $file->file_size,
            'file_second' => null,
            'created_at' => $file->created_at,
            'deleted_at' => $file->deleted_at,
            'delete_reason' => null,
            'author' => $file->author,
            'uploader' => $file->uploader,
            'work' => $file->work,
            'chapter' => null,
            'is_current' => (bool) $current,
        ];
    }
}
