<?php

namespace App\Services;

use App\Models;
use Illuminate\Support\Facades\DB;

class AudioResources
{
    public function __construct(private Chapters $chapters, private Activity $activity, private SyncScheduler $sync, private HistoryRestore $restore, private WorkState $state, private UploadChunks $chunks) {}

    public function duplicates(string $hash)
    {
        return Models\Media::query()->select(Models\Media::FIELDS)->whereHas('file', fn ($q) => $q->withTrashed()->where(fn ($f) => $f->where('sha256', $hash)->orWhere('source_sha256', $hash)))
            ->with(['work' => fn ($q) => $q->withTrashed()->select(['id', 'name', 'type']), 'chapter:id,title,season_number,episode_number', 'uploader:id,username,pseudonym'])->orderBy('id');
    }

    public function destination(int $work, ?int $chapterId, int $season, int $episode, bool $alternative): ?Models\Chapter
    {
        $query = Models\Chapter::query()->select(Models\Chapter::FIELDS)->where('abs_list_id', $work);
        $chapter = $chapterId ? $query->find($chapterId) : $query->where('season_number', $season)->where('episode_number', $episode)->first();
        abort_if($chapterId && ! $chapter, 409, '目标章节已合并或删除，请重新选择上传目标。');
        abort_if($chapter && ! $alternative && $chapter->resources()->exists(), 409, '目标章节已有音频。请允许同章多份资源，并选择主资源处理方式。');

        return $chapter;
    }

    public function chapter(int $id): Models\Chapter
    {
        return Models\Chapter::query()->select(Models\Chapter::FIELDS)->withResources()->findOrFail($id);
    }

    public function publish(Models\User $user, Models\Work $work, Models\StoredFile $file, array $data, array $probe, bool $reference = false): array
    {
        $chapter = $this->destination($work->id, $data['chapter_id'] ?? null, $data['season_number'], $data['episode_number'], $data['allow_alternative'] ?? false);
        $before = $this->restore->capture($chapter ? [$chapter->id] : []);
        if (! $chapter) {
            $chapter = $this->chapters->create($work, $user, ['title' => ($data['title'] ?? null) ?: pathinfo($data['file_name'], PATHINFO_FILENAME), 'season_number' => $data['season_number'], 'episode_number' => $data['episode_number']]);
        }
        $media = Models\Media::create(['file_id' => $file->id, 'abs_list_id' => $work->id, 'abs_item_id' => $chapter->id, 'user_id' => $user->id,
            'name' => $data['file_name'], 'file_size' => $file->file_size, 'file_second' => $probe['duration'], 'file_metadata' => $probe['probe'], 'is_reference' => $reference]);
        $primary = $chapter->primary_media_id;
        if ($chapter->resources()->count() === 1 || ($data['primary_choice'] ?? null) === 'new') {
            $primary = $media->id;
        }
        abort_unless($primary && $chapter->resources()->whereKey($primary)->exists(), 422, '请先为章节选择主资源。');
        $chapter->update(['primary_media_id' => $primary]);
        $this->activity->record($user, $reference ? 'audio.referenced' : 'audio.uploaded', $media->name, $work->id, [
            'media_id' => $media->id, 'file_id' => $file->id, 'chapter_id' => $chapter->id, 'season' => $chapter->season_number, 'episode' => $chapter->episode_number,
            'upload_id' => $data['upload_id'] ?? null, 'request_id' => $data['request_id'] ?? null, 'primary_media_id' => $primary, 'bytes' => $reference ? 0 : $data['file_size'], 'source_media_id' => $data['reference_media_id'] ?? null,
            ...$this->restore->details($before, $this->restore->capture([$chapter->id], [$media->id]))]);
        $this->sync->mark($work);

        return ['file_id' => $file->id, 'media_id' => $media->id, 'completed' => true, 'chapter' => $this->chapter($chapter->id), 'work' => $this->state->read($work->id)];
    }

    public function reference(Models\User $user, array $data): array
    {
        return DB::transaction(function () use ($user, $data) {
            $work = Models\Work::query()->select(Models\Work::FIELDS)->lockForUpdate()->findOrFail($data['work_id']);
            $previous = Models\ActivityLog::query()->select(['details'])->where('work_id', $work->id)->where('user_id', $user->id)->where('action', 'audio.referenced')->where('details->request_id', $data['request_id'])->first();
            if ($previous) {
                $media = Models\Media::query()->select(['id', 'abs_list_id', 'abs_item_id', 'file_id'])->find($previous->details['media_id']);
                abort_unless($media, 409, '这次引用已完成但资源已下架，请重新选择文件。');

                return ['file_id' => $media->file_id, 'media_id' => $media->id, 'completed' => true, 'chapter' => $media->abs_list_id === $work->id ? $this->chapter($media->abs_item_id) : null, 'work' => $this->state->read($work->id)];
            }
            $source = Models\Media::query()->select([...Models\Media::FIELDS, 'file_metadata'])->findOrFail($data['reference_media_id']);
            $file = Models\StoredFile::withTrashed()->select(['id', 'file_size', 'sha256', 'source_sha256', 'deleted_at'])->lockForUpdate()->findOrFail($source->file_id);
            abort_unless(Models\Media::query()->whereKey($source->id)->exists(), 409, '这份音频刚刚下架，请重新检查重复文件。');
            abort_if(! empty($data['sha256']) && ! in_array($data['sha256'], [$file->sha256, $file->source_sha256], true), 409, '引用资源与所选文件不一致，请重新检查重复文件。');
            if ($file->trashed()) {
                $file->restore();
            }
            $result = $this->publish($user, $work, $file, $data, ['duration' => $source->file_second, 'probe' => $source->file_metadata], true);
            if (! empty($data['upload_id'])) {
                $session = Models\UploadSession::query()->where('user_id', $user->id)->whereKey($data['upload_id'])->where('kind', 'audio')->where('abs_list_id', $work->id)->where('status', 'uploading')->update(['status' => 'completed', 'file_id' => $file->id]);
                if ($session) {
                    DB::afterCommit(fn () => $this->chunks->discard($data['upload_id']));
                }
            }

            return $result;
        });
    }
}
