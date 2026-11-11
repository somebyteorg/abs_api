<?php

namespace App\Http\Controllers;

use App\Models;
use App\Services;
use DirectoryIterator;
use FilesystemIterator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Ramsey\Uuid\Uuid;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class QbtController extends Controller
{
    public function index(Request $request)
    {
        return response()->view('qbt.index', ['sourceRoot' => config('filesystems.disks.qbt.root'), 'actor' => $request->user()->display_name])->header('Cache-Control', 'no-store');
    }

    public function directories(Request $request)
    {
        $data = $request->validate(['path' => ['nullable', 'string', 'max:4096']]);
        $names = [];
        foreach (new DirectoryIterator($this->sourcePath($data['path'] ?? '')) as $file) {
            if (! $file->isDot() && ! $file->isLink() && $file->isDir()) {
                $names[] = $file->getFilename();
            }
        }
        usort($names, 'strnatcasecmp');

        return ['items' => $names, 'saved' => $this->savedDirectories()];
    }

    public function files(Request $request)
    {
        $data = $request->validate(['path' => ['required', 'string', 'max:4096']]);
        $root = $this->sourceRoot();
        $paths = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->sourcePath($data['path']), FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file->isLink() && $file->isFile() && in_array(strtolower($file->getExtension()), config('contribution.audio_extensions'), true)) {
                $paths[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        usort($paths, 'strnatcasecmp');
        $ids = array_map(fn ($path) => self::sourceId('source', $root.'/'.$path), $paths);
        $processed = $this->importedFiles($ids);

        return ['items' => array_map(fn ($path, $id) => ['path' => $path, 'result' => $processed[$id] ?? null], $paths, $ids)];
    }

    public function works(Request $request)
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $term = $data['q'] ?? '';
        $page = Models\Work::query()->select(['id', 'name', 'tagline', 'date_air', 'cover_file_id', 'type', 'abs_library_id'])->visibleTo($request->user())
            ->with('library:id,name')->when($term !== '', fn ($query) => $query->where(fn ($query) => $query->matching($term)->when(ctype_digit($term), fn ($query) => $query->orWhere('id', (int) $term))))
            ->orderByDesc('id')->paginate(10);

        return $this->FormatPage($page, $page->items());
    }

    public function import(Request $request, Services\AudioMetadata $metadata, Services\AudioResources $resources)
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:4096'], 'work_id' => ['required', 'integer', 'min:1'],
            'season' => ['required', 'integer', 'min:1', 'max:100000'], 'episode' => ['present', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'duplicates' => ['required', Rule::in(['skip', 'reference'])],
        ]);

        $user = $request->user();
        $absolute = $this->sourcePath($data['path'], false);
        $sourceId = self::sourceId('source', $absolute);
        if ($previous = $this->existing($sourceId)) {
            return $this->savedResponse($data['path'], $previous);
        }
        $file = ['file_path' => $this->absPath($absolute), 'file_name' => basename($absolute), 'file_size' => filesize($absolute)];
        abort_unless(in_array(strtolower(pathinfo($file['file_name'], PATHINFO_EXTENSION)), config('contribution.audio_extensions'), true), 422, '请选择支持的音频文件。');
        Validator::make([...$file, 'title' => pathinfo($file['file_name'], PATHINFO_FILENAME)], [
            'file_path' => ['required', 'string', 'max:255'], 'file_name' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'], 'file_size' => ['required', 'integer', 'min:1'],
        ])->validate();
        $hash = hash_file('sha256', $absolute);
        abort_unless($hash !== false, 422, '无法读取音频文件。');
        $file += ['id' => self::sourceId('file', $file['file_path']), 'sha256' => $hash, 'source_sha256' => $hash,
            'mime_type' => (new \finfo(FILEINFO_MIME_TYPE))->file($absolute) ?: 'application/octet-stream'];

        $entry = Cache::lock('audio-hash:'.$hash, 180)->block(10, function () use ($user, $data, $sourceId, $file, $metadata, $resources) {
            if ($previous = $this->existing($sourceId)) {
                return $previous;
            }
            $duplicateId = $resources->duplicates($file['sha256'])->withoutEagerLoads()->select('id')->value('id');
            $probe = $duplicateId ? null : $metadata->probe($file['file_path']);

            return DB::transaction(function () use ($user, $data, $sourceId, $file, $duplicateId, $probe, $resources) {
                $work = Models\Work::query()->select(Models\Work::FIELDS)->visibleTo($user)->lockForUpdate()->findOrFail($data['work_id']);
                if ($previous = $this->existing($sourceId)) {
                    return $previous;
                }
                $result = null;
                $resource = [
                    'work_id' => $work->id, 'request_id' => $sourceId, 'sha256' => $file['sha256'],
                    'file_name' => $file['file_name'], 'file_size' => $file['file_size'], 'season_number' => $data['season'],
                    'episode_number' => $data['episode'] ?? ((int) Models\Chapter::query()->where('abs_list_id', $work->id)->where('season_number', $data['season'])->max('episode_number')) + 1,
                ];
                if (! $duplicateId) {
                    abort_if(Models\StoredFile::withTrashed()->whereKey($file['id'])->exists(), 409, '这个路径已经有文件记录，未自动覆盖或恢复。');
                    $stored = Models\StoredFile::create([...$file, 'user_id' => $user->id, 'abs_list_id' => $work->id, 'file_type' => 'audio']);
                    $result = $resources->publish($user, $work, $stored, $resource, $probe);
                    Models\User::query()->whereKey($user->id)->increment('size_upload', $file['file_size']);
                } elseif ($data['duplicates'] === 'reference') {
                    $result = $resources->reference($user, [...$resource, 'reference_media_id' => $duplicateId]);
                }

                return [
                    'work_id' => $work->id,
                    'season_number' => $result['chapter']->season_number ?? null, 'episode_number' => $result['chapter']->episode_number ?? null,
                    'media_id' => $result['media_id'] ?? null, 'status' => $result ? ($duplicateId ? 'referenced' : 'imported') : 'skipped',
                ];
            });
        });

        return $this->savedResponse($data['path'], $entry);
    }

    private function sourceRoot(): string
    {
        $root = realpath((string) config('filesystems.disks.qbt.root'));
        abort_unless($root && is_dir($root) && is_readable($root), 422, 'QBT_STORAGE_ROOT 不存在或不可读。');

        return $root;
    }

    private function sourcePath(string $relative, bool $directory = true): string
    {
        $parts = explode('/', $relative);
        abort_if(str_contains($relative, "\0") || str_contains($relative, '\\') || array_intersect($parts, ['.', '..']) || ($relative !== '' && in_array('', $parts, true)), 422, '目录路径不正确。');
        $absolute = $this->sourceRoot().($relative === '' ? '' : '/'.$relative);
        abort_unless(realpath($absolute) === $absolute && is_readable($absolute) && ($directory ? is_dir($absolute) : is_file($absolute)), 422, '目录或文件不存在、不可读，或包含符号链接。');

        return $absolute;
    }

    private function absPath(string $absolute): string
    {
        $root = realpath((string) config('filesystems.disks.abs.root'));
        abort_unless($root && str_starts_with($absolute, rtrim($root, '/').'/'), 422, '来源文件必须位于 ABS_STORAGE_ROOT 内。');

        return substr($absolute, strlen(rtrim($root, '/')) + 1);
    }

    private static function sourceId(string $kind, string $path): string
    {
        return (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'emos:qbt:'.$kind.':'.$path);
    }

    private function existing(string $id): ?array
    {
        return $this->importedFiles([$id])[$id] ?? null;
    }

    private function importedFiles(array $ids): array
    {
        if (! $ids) {
            return [];
        }

        return Models\ActivityLog::query()->select(['work_id', 'action', 'details->request_id as request_id', 'details->media_id as media_id', 'details->season as season_number', 'details->episode as episode_number'])
            ->whereIn('action', ['audio.uploaded', 'audio.referenced'])->whereIn('details->request_id', $ids)->get()
            ->mapWithKeys(fn ($log) => [$log->request_id => [
                'status' => $log->action === 'audio.uploaded' ? 'imported' : 'referenced', 'work_id' => $log->work_id,
                'media_id' => $log->media_id === null ? null : (int) $log->media_id,
                'season_number' => $log->season_number === null ? null : (int) $log->season_number,
                'episode_number' => $log->episode_number === null ? null : (int) $log->episode_number,
            ]])->all();
    }

    private function savedDirectories(): array
    {
        $file = $this->sourceRoot().'/saved.json';

        return File::exists($file) ? json_decode(File::get($file), true, 512, JSON_THROW_ON_ERROR) : [];
    }

    private function savedResponse(string $path, array $result): array
    {
        $root = $this->sourceRoot();
        $updated = Cache::lock('qbt-saved:'.sha1($root), 10)->block(3, function () use ($path, $root) {
            $saved = $this->savedDirectories();
            $updated = [];
            $now = now()->toIso8601String();
            for ($directory = dirname($path); $directory !== '.'; $directory = dirname($directory)) {
                $saved[$directory] ??= $now;
                $updated[$directory] = $saved[$directory];
            }
            File::replace($root.'/saved.json', json_encode((object) $saved, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

            return $updated;
        });

        return ['data' => $result, 'saved' => $updated];
    }
}
