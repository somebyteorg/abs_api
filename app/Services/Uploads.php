<?php

namespace App\Services;

use App\Models;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Uploads
{
    public function __construct(private AudioMetadata $metadata, private AudioResources $resources, private UploadChunks $chunks) {}

    public function session(string $id, Models\User $user): Models\UploadSession
    {
        $upload = Models\UploadSession::query()->select(Models\UploadSession::FIELDS)->where('user_id', $user->id)->findOrFail($id);
        if ($upload->status === 'completed' && $upload->kind === 'audio') {
            $log = Models\ActivityLog::query()->select(['details'])->whereIn('action', ['audio.uploaded', 'audio.referenced'])->where('user_id', $user->id)->where(fn ($q) => $q->where('details->upload_id', $upload->id)->orWhere(fn ($q) => $q->where('action', 'audio.uploaded')->where('details->file_id', $upload->id)))->latest('id')->first();
            abort_unless($log && Models\Media::query()->whereKey($log->details['media_id'])->exists(), 410, '此前上传的资源已下架，请重新选择文件上传。');
        }
        abort_if($upload->status !== 'completed' && ($upload->status !== 'uploading' || $upload->expires_at->isPast()), 410, '这批分片过期或已取消，请重新选择文件。');

        return $upload;
    }

    public function start(Models\User $user, array $data): array
    {
        $authorId = $data['author_id'] ?? null;
        $workId = $data['work_id'] ?? null;
        abort_if($authorId && $workId, 422, '只能选择一个上传目标。');
        abort_if($data['kind'] === 'audio' && ! $workId, 422, '音频需要选择目标作品。');
        if ($authorId) {
            abort_unless($data['kind'] === 'cover', 422, '作者资料只接受封面图片。');
            Models\Author::query()->select(['id'])->findOrFail($authorId);
        } elseif ($workId) {
            Models\Work::query()->select(['id'])->findOrFail($workId);
        }
        $extension = strtolower(pathinfo($data['file_name'], PATHINFO_EXTENSION));
        $extensions = config('contribution.'.($data['kind'] === 'cover' ? 'cover_extensions' : 'audio_extensions'));
        abort_unless(in_array($extension, $extensions, true), 422, '支持的文件格式：'.implode('、', array_map('strtoupper', $extensions)).'。');
        $chapter = null;
        if ($data['kind'] === 'audio') {
            $chapter = $this->resources->destination($workId, $data['chapter_id'] ?? null, $data['season_number'], $data['episode_number'], $data['allow_alternative'] ?? false);
        }
        $chunkSize = min(config('contribution.chunk_size'), config('contribution.max_chunk_size'));
        $upload = Models\UploadSession::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'abs_list_id' => $workId, 'author_id' => $authorId,
            'kind' => $data['kind'], 'file_name' => $data['file_name'], 'file_size' => $data['file_size'],
            'sha256' => $data['sha256'] ?? null, 'chunk_size' => $chunkSize, 'chunk_count' => (int) ceil($data['file_size'] / $chunkSize),
            'season_number' => $chapter?->season_number ?? $data['season_number'], 'episode_number' => $chapter?->episode_number ?? $data['episode_number'],
            'chapter_id' => $chapter?->id, 'allow_alternative' => $data['allow_alternative'] ?? false, 'primary_choice' => $data['primary_choice'] ?? null,
            'title' => $data['title'] ?? null, 'status' => 'uploading', 'expires_at' => now()->addDay(),
        ]);

        return $this->describe($upload);
    }

    public function describe(Models\UploadSession $upload): array
    {
        return [
            'id' => $upload->id,
            'status' => $upload->status,
            'chunk_size' => $upload->chunk_size,
            'chunk_count' => $upload->chunk_count,
            'received' => $this->chunks->received($upload),
            'file_id' => $upload->file_id,
            'expires_at' => $upload->expires_at,
        ];
    }

    public function chunk(string $id, Models\User $user, int $index, UploadedFile $chunk): array
    {
        return Cache::lock('upload:'.$id, 600)->block(3, function () use ($id, $user, $index, $chunk) {
            $this->chunks->store($this->session($id, $user), $index, $chunk);

            return ['index' => $index, 'received' => true];
        });
    }

    public function complete(string $id, Models\User $user): array
    {
        return Cache::lock('upload:'.$id, 600)->block(3, function () use ($id, $user) {
            $upload = $this->session($id, $user);
            if ($upload->status === 'completed') {
                $this->chunks->discard($id);

                return ['file_id' => $upload->file_id, 'completed' => true];
            }
            if ($upload->kind === 'audio') {
                $this->resources->destination($upload->abs_list_id, $upload->chapter_id, $upload->season_number, $upload->episode_number, $upload->allow_alternative);
            }
            $temporary = $this->chunks->assemble($upload);
            $hash = hash_file('sha256', $temporary);
            abort_if($upload->sha256 && ! hash_equals($upload->sha256, $hash), 422, '文件内容校验不一致，请重新选择原文件上传。');

            $publish = fn () => $this->publish($upload, $user, $temporary, $hash);
            $result = $upload->kind === 'audio'
                ? Cache::lock('audio-hash:'.$hash, 180)->block(10, $publish)
                : $publish();
            if (empty($result['duplicate'])) {
                $this->chunks->discard($id);
            }

            return $result;
        });
    }

    private function publish(Models\UploadSession $upload, Models\User $user, string $temporary, string $hash): array
    {
        if ($upload->kind === 'audio' && $this->resources->duplicates($hash)->exists()) {
            return [
                'duplicate' => true,
                'sha256' => $hash,
                'matches' => $this->resources->duplicates($hash)->limit(30)->get(),
                'upload_id' => $upload->id,
            ];
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($temporary);
        if ($upload->kind === 'cover') {
            abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true) && @getimagesize($temporary), 422, '封面不是有效的图片，请换一个文件。');
        }
        $path = $upload->created_at->format('Y/m/d').'/'.$upload->id.'/file.'.strtolower(pathinfo($upload->file_name, PATHINFO_EXTENSION));
        $disk = Storage::disk('abs');
        $disk->makeDirectory(dirname($path));
        abort_unless(copy($temporary, $disk->path($path)), 500, '资源暂时无法保存，请重试。');
        $probe = $upload->kind === 'audio' ? $this->metadata->probe($path) : null;

        return DB::transaction(function () use ($upload, $user, $path, $mime, $probe, $hash) {
            $work = $upload->kind === 'audio' ? Models\Work::query()->select(Models\Work::FIELDS)->lockForUpdate()->findOrFail($upload->abs_list_id) : null;
            $file = Models\StoredFile::create([
                'id' => $upload->id,
                'abs_list_id' => $work?->id,
                'author_id' => null,
                'file_type' => $upload->kind,
                'file_name' => $upload->file_name,
                'file_size' => $upload->file_size,
                'file_path' => $path,
                'mime_type' => $mime,
                'source_sha256' => $hash,
                'sha256' => $hash,
                'user_id' => $user->id,
            ]);
            $result = $work
                ? $this->resources->publish($user, $work, $file, [
                    'upload_id' => $upload->id,
                    ...$upload->only(['file_name', 'file_size', 'chapter_id', 'season_number', 'episode_number', 'allow_alternative', 'primary_choice', 'title']),
                ], $probe)
                : ['file_id' => $file->id, 'url' => Models\StoredFile::previewUrl($file->id), 'completed' => true];
            Models\User::query()->whereKey($user->id)->increment('size_upload', $upload->file_size);
            $upload->update(['status' => 'completed', 'file_id' => $file->id]);

            return $result;
        });
    }

    public function cancel(string $id, Models\User $user): void
    {
        Cache::lock('upload:'.$id, 600)->block(3, function () use ($id, $user) {
            $upload = $this->session($id, $user);
            abort_if($upload->status === 'completed', 409, '完成的资源请从作品页删除。');
            $upload->update(['status' => 'cancelled']);
            $this->chunks->discard($id);
        });
    }
}
