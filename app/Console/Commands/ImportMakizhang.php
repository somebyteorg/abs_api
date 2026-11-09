<?php

namespace App\Console\Commands;

use App\Models;
use App\Services;
use Generator;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Ramsey\Uuid\Uuid;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class ImportMakizhang extends Command
{
    private const CATEGORIES = ['佛经', '儿童', '完本', '热更', '相声评书'];

    private string $storageRoot;

    private Services\AudioMetadata $audioMetadata;

    private Services\Authors $authors;

    protected $signature = 'abs:import-makizhang
        {path? : makizhang 目录的绝对路径，默认 ABS_STORAGE_ROOT/makizhang}
        {--user= : 创建者和贡献归属的 API 用户 ID，实际导入时必填}
        {--category=* : 只导入指定分类，可重复指定}
        {--work=* : 只导入指定作品目录名，可重复指定}
        {--duplicates=skip : 相同音频的处理方式：skip 跳过，reference 新增引用}
        {--dry-run : 仅扫描目录，不读写数据库、不计算哈希、不调用元数据服务}';

    protected $description = '原地导入 makizhang 的音频、作品资料和封面，等待定时任务同步到 ABS';

    public function handle(Services\AudioMetadata $metadata, Services\Authors $authors): int
    {
        $this->audioMetadata = $metadata;
        $this->authors = $authors;

        $lock = null;
        try {
            $categories = $this->option('category') ?: self::CATEGORIES;
            if (array_diff($categories, self::CATEGORIES)) {
                throw new RuntimeException('分类只能选择：'.implode('、', self::CATEGORIES));
            }
            $duplicates = $this->option('duplicates');
            if (! in_array($duplicates, ['skip', 'reference'], true)) {
                throw new RuntimeException('--duplicates 只能为 skip 或 reference。');
            }
            $root = $this->resolveRoot($this->argument('path'));
            $dryRun = (bool) $this->option('dry-run');
            $this->line('资源目录：'.$root);
            $this->line('存储根目录：'.config('filesystems.disks.abs.root'));

            $user = null;
            $libraries = collect();
            if (! $dryRun) {
                $id = $this->option('user');
                if (! is_string($id) || ! ctype_digit($id) || (int) $id <= 0) {
                    throw new RuntimeException('请用 --user 指定资源和贡献归属的 API 用户 ID。');
                }
                $user = Models\User::query()->select(['id', 'username', 'pseudonym', 'role'])->find($id);
                if (! $user || ! $user->canContribute()) {
                    throw new RuntimeException('导入账号不存在或没有贡献权限。');
                }
                if (trim((string) config('services.metadata.global')) === '') {
                    throw new RuntimeException('请先配置 METADATA_URL_GLOBAL，新增音频需要远程识别。');
                }
                $matches = Models\Library::query()->select(['id', 'name', 'type'])->whereIn('name', $categories)->get()->groupBy('name');
                foreach ($categories as $category) {
                    if (! is_dir($root.'/'.$category)) {
                        continue;
                    }
                    $matchesForCategory = $matches->get($category, collect())->where('type', 'book');
                    if ($matchesForCategory->count() !== 1) {
                        throw new RuntimeException('分类「'.$category.'」必须有且仅有一个同名的有声书分类，请先在管理页面整理。');
                    }
                    $libraries->put($category, $matchesForCategory->first());
                }
                // flock releases automatically on exit, including interruption during a long import.
                $lock = fopen(storage_path('framework/makizhang-import.lock'), 'c');
                if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
                    throw new RuntimeException('另一个 makizhang 导入命令正在运行，或无法创建导入锁。');
                }
                $this->ensureMetadataColumn();
                $this->line('导入归属：'.$user->display_name.'（'.$user->id.'），相同音频：'.$duplicates);
            }

            $totals = ['works' => 0, 'audio' => 0, 'imported' => 0, 'referenced' => 0, 'existing' => 0, 'duplicates' => 0, 'empty' => 0, 'failed' => 0, 'covers' => 0, 'bytes' => 0];
            foreach ($this->scanWorks($root, array_values(array_unique($categories)), $this->option('work')) as $plan) {
                if (! $plan['audio']) {
                    $this->warn('跳过没有音频的目录：'.$plan['relative']);

                    continue;
                }
                $totals['works']++;
                $totals['audio'] += count($plan['audio']);
                $this->newLine();
                $this->line($plan['category'].' / '.$plan['name'].'：'.count($plan['audio']).' 个音频，'.count($plan['groups']).' 个分组，封面'.($plan['cover'] ? '有' : '无'));
                foreach ($plan['warnings'] as $warning) {
                    $this->warn($warning);
                }
                if ($dryRun) {
                    $totals['empty'] += count(array_filter($plan['audio'], fn ($file) => $file['size'] === 0));
                    $totals['covers'] += (int) ($plan['cover'] !== null);
                    $totals['bytes'] += array_sum(array_column($plan['audio'], 'size'));
                    foreach ($plan['groups'] as $index => $group) {
                        $this->line('  首次分组 S'.($index + 1).'：'.($group === $plan['path'] ? '作品目录' : substr($group, strlen($plan['path']) + 1)));
                    }

                    continue;
                }
                $bar = $this->output->createProgressBar(count($plan['audio']));
                $bar->start();
                try {
                    $result = $this->importWork($plan, $libraries->get($plan['category']), $user, $duplicates, fn () => $bar->advance());
                } finally {
                    $bar->finish();
                    $this->newLine();
                }
                foreach (array_keys($totals) as $key) {
                    if (isset($result[$key])) {
                        $totals[$key] += $result[$key];
                    }
                }
                $this->line('作品 #'.$result['work_id'].'：新增 '.$result['imported'].'，引用 '.$result['referenced'].'，已处理 '.$result['existing'].'，相同跳过 '.$result['duplicates'].'，空文件 '.$result['empty'].'，失败 '.$result['failed']);
                foreach ($result['errors'] as $error) {
                    $this->warn($error);
                }
            }
            if ($totals['works'] === 0) {
                throw new RuntimeException('没有找到匹配的作品，请检查路径、分类和作品目录名。');
            }
            $this->newLine();
            $this->table(['作品', '音频', '新增', '引用', '已处理', '相同跳过', '空文件', '失败', '封面', $dryRun ? '音频大小 GiB' : '新增贡献 GiB'], [[
                $totals['works'], $totals['audio'], $totals['imported'], $totals['referenced'], $totals['existing'],
                $totals['duplicates'], $totals['empty'], $totals['failed'], $totals['covers'], number_format($totals['bytes'] / 1024 ** 3, 2),
            ]]);
            $this->info($dryRun ? '预览结束，未读写数据库或请求元数据服务。' : '导入进度已保存，作品等待现有定时任务同步；可用相同命令继续处理失败文件。');

            return $totals['failed'] > 0 ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function ensureMetadataColumn(): void
    {
        $schema = (new Models\Work)->getConnection()->getSchemaBuilder();
        if (! $schema->hasColumn('abs_lists', 'metadata')) {
            $schema->table('abs_lists', fn (Blueprint $table) => $table->jsonb('metadata')->default('{}'));
        }
    }

    private function resolveRoot(?string $path): string
    {
        $storageRoot = realpath(Storage::disk('abs')->path(''));
        if ($storageRoot === false || ! is_readable($storageRoot)) {
            throw new RuntimeException('ABS_STORAGE_ROOT 不存在或不可读，请先配置路径并挂载文件目录。');
        }
        $this->storageRoot = rtrim($storageRoot, DIRECTORY_SEPARATOR);
        $root = realpath($path ?: $this->storageRoot.'/makizhang');
        if ($root === false || ! is_dir($root) || ! is_readable($root)) {
            throw new RuntimeException('导入目录不存在或不可读，请传入 makizhang 目录的完整路径。');
        }
        $this->relativePath($root);

        return $root;
    }

    private function relativePath(string $path): string
    {
        $resolved = realpath($path);
        if ($resolved === false || ! str_starts_with($resolved, $this->storageRoot.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('导入文件必须位于 ABS_STORAGE_ROOT 内：'.$path);
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', substr($resolved, strlen($this->storageRoot) + 1));
    }

    private static function sourceId(string $kind, string $path): string
    {
        return (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'emos:import-makizhang:v1:'.$kind.':'.$path);
    }

    private function scanWorks(string $root, array $categories, array $names): Generator
    {
        foreach ($categories as $category) {
            $directory = $root.'/'.$category;
            if (! is_dir($directory) || is_link($directory)) {
                continue;
            }
            $directories = glob($directory.'/*', GLOB_ONLYDIR) ?: [];
            usort($directories, 'strnatcmp');
            foreach ($directories as $path) {
                if (is_link($path) || ($names && ! in_array(basename($path), $names, true))) {
                    continue;
                }
                yield $this->inspectWork($path, $category);
            }
        }
    }

    private function inspectWork(string $path, string $category): array
    {
        $audio = [];
        $covers = [];
        $documents = [];
        $warnings = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink() || ! $file->isFile()) {
                continue;
            }
            $absolute = $file->getPathname();
            $relative = $this->relativePath($absolute);
            $name = strtolower($file->getFilename());
            $extension = strtolower($file->getExtension());
            if (in_array($extension, config('contribution.audio_extensions'), true)) {
                $audio[] = [
                    'path' => $absolute, 'relative' => $relative, 'name' => $file->getFilename(),
                    'group' => $file->getPath(), 'size' => $file->getSize(), 'mtime' => $file->getMTime(),
                    'id' => self::sourceId('chapter', $relative),
                ];
            } elseif ($name === 'metadata.json') {
                try {
                    $data = json_decode(file_get_contents($absolute), true, 512, JSON_THROW_ON_ERROR);
                    if (! is_array($data) || array_is_list($data)) {
                        throw new RuntimeException('metadata.json 必须是对象');
                    }
                    $documents[$file->getPath()] = [...$data, ...(is_array($data['metadata'] ?? null) ? $data['metadata'] : [])];
                    unset($documents[$file->getPath()]['metadata'], $documents[$file->getPath()]['chapters']);
                } catch (\Throwable $exception) {
                    $warnings[] = $relative.'：'.$exception->getMessage();
                }
            } elseif (pathinfo($name, PATHINFO_FILENAME) === 'cover' && in_array($extension, config('contribution.cover_extensions'), true)) {
                $covers[] = $absolute;
            }
        }
        usort($audio, fn ($a, $b) => strnatcmp($a['relative'], $b['relative']));
        uksort($documents, 'strnatcmp');
        usort($covers, fn ($a, $b) => substr_count($a, '/') <=> substr_count($b, '/') ?: strnatcmp($a, $b));

        foreach ($audio as &$file) {
            $file['title'] = pathinfo($file['name'], PATHINFO_FILENAME);
        }
        unset($file);

        $preferred = $documents[$path] ?? (count($documents) === 1 ? reset($documents) : []);
        $metadata = [];
        foreach (['narrators', 'tags', 'genres', 'authors'] as $field) {
            $values = [];
            foreach ($documents as $data) {
                foreach (is_array($data[$field] ?? null) ? $data[$field] : [] as $value) {
                    if (is_string($value) && trim($value) !== '') {
                        $values[] = trim($value);
                    }
                }
            }
            $metadata[$field] = array_values(array_unique($values));
        }
        foreach (['publishedYear' => 'published_year', 'publisher' => 'publisher', 'language' => 'language', 'isbn' => 'isbn', 'asin' => 'asin'] as $field => $column) {
            $values = array_unique(array_filter(array_map(fn ($data) => is_scalar($data[$field] ?? null) ? trim((string) $data[$field]) : '', $documents)));
            $metadata[$column] = count($values) === 1 ? reset($values) : null;
        }
        if (! preg_match('/^\d{4}$/', $metadata['published_year'] ?? '')) {
            $metadata['published_year'] = null;
        }
        $metadata['abridged'] = ($preferred['abridged'] ?? false) === true;
        $name = isset($documents[$path]) && is_string($preferred['title'] ?? null) && trim($preferred['title']) !== '' ? trim($preferred['title']) : basename($path);
        $description = $this->plainText($preferred['description'] ?? null);
        if ($description === null) {
            $descriptions = array_unique(array_filter(array_map(fn ($data) => $this->plainText($data['description'] ?? null), $documents)));
            $description = $descriptions ? implode("\n\n", $descriptions) : null;
        }
        $tagline = $this->plainText($preferred['subtitle'] ?? null);
        $date = $preferred['publishedDate'] ?? null;
        $date = is_string($date) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $date : null;
        $authors = $metadata['authors'];
        unset($metadata['authors']);

        return [
            'path' => $path, 'relative' => $this->relativePath($path), 'category' => $category, 'name' => $name,
            'attributes' => ['metadata' => $metadata, 'name' => $name, 'tagline' => $tagline, 'description' => $description, 'date_air' => $date, 'is_adult' => (bool) array_filter($documents, fn ($data) => ($data['explicit'] ?? false) === true)],
            'authors' => $authors, 'audio' => $audio, 'cover' => $covers[0] ?? null,
            'groups' => array_values(array_unique(array_column($audio, 'group'))), 'warnings' => $warnings,
        ];
    }

    private function plainText(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $value = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $value);
        $value = preg_replace('~<br\s*/?>|</(?:p|div|li|h[1-6])\s*>~i', "\n", $value);
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\n[\t ]*\n(?:[\t ]*\n)+/', "\n\n", $value)) ?: null;
    }

    private function importWork(array $plan, Models\Library $library, Models\User $user, string $duplicates, ?callable $progress = null): array
    {
        $result = ['imported' => 0, 'referenced' => 0, 'existing' => 0, 'duplicates' => 0, 'empty' => 0, 'failed' => 0, 'covers' => 0, 'bytes' => 0, 'errors' => []];
        $work = $this->findOrCreateWork($plan, $library, $user);
        $existing = Models\Chapter::withTrashed()->select(['id', 'abs_id', 'season_number', 'abs_list_id'])
            ->whereIn('abs_id', array_column($plan['audio'], 'id'))->get()->keyBy('abs_id');
        $occupied = Models\Chapter::query()->select(['season_number', 'episode_number'])->where('abs_list_id', $work->id)->get();
        $seasons = [];
        $used = [];
        $maximum = [];
        foreach ($occupied as $chapter) {
            $used[$chapter->season_number][$chapter->episode_number] = true;
            $maximum[$chapter->season_number] = max($maximum[$chapter->season_number] ?? 0, $chapter->episode_number);
        }
        foreach ($plan['audio'] as $file) {
            $chapter = $existing->get($file['id']);
            if ($chapter && $chapter->abs_list_id === $work->id) {
                $seasons[$file['group']] ??= $chapter->season_number;
            }
        }
        $nextSeason = max([0, ...array_keys($used), ...array_values($seasons)]) + 1;
        foreach ($plan['groups'] as $group) {
            $seasons[$group] ??= $nextSeason++;
        }
        $positions = [];
        foreach ($plan['audio'] as $file) {
            $positions[$file['group']] = ($positions[$file['group']] ?? 0) + 1;
            try {
                if ($existing->has($file['id'])) {
                    // Keep later edits, moves, merges and soft deletions intact on a repeated import.
                    $result['existing']++;

                    continue;
                }
                if ($file['size'] === 0) {
                    $result['empty']++;
                    $this->recordProblem($result, $file['relative'], '零字节音频，已跳过。');

                    continue;
                }
                $season = $seasons[$file['group']];
                $episode = $positions[$file['group']];
                if (isset($used[$season][$episode])) {
                    $episode = ($maximum[$season] ?? 0) + 1;
                }
                $outcome = $this->importAudio($work->id, $user, $file, $season, $episode, $duplicates);
                $result[$outcome['status']]++;
                $result['bytes'] += $outcome['bytes'];
                if (in_array($outcome['status'], ['imported', 'referenced'], true)) {
                    $used[$season][$episode] = true;
                    $maximum[$season] = max($maximum[$season] ?? 0, $episode);
                }
            } catch (QueryException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                $result['failed']++;
                $this->recordProblem($result, $file['relative'], $exception->getMessage());
            } finally {
                if ($progress) {
                    $progress();
                }
            }
        }
        if ($plan['cover']) {
            try {
                $bytes = $this->importCover($work->id, $user, $plan['cover']);
                if ($bytes > 0) {
                    $result['covers']++;
                    $result['bytes'] += $bytes;
                }
            } catch (QueryException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                $result['failed']++;
                $this->recordProblem($result, $plan['relative'], '封面：'.$exception->getMessage());
            }
        }

        return ['work_id' => $work->id, ...$result];
    }

    private function findOrCreateWork(array $plan, Models\Library $library, Models\User $user): Models\Work
    {
        Validator::make($plan['attributes'], [
            'name' => ['required', 'string', 'max:120'], 'tagline' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:10000'],
            'metadata' => ['required', 'array'],
            'metadata.narrators' => ['array'], 'metadata.narrators.*' => ['string', 'max:255'],
            'metadata.genres' => ['array'], 'metadata.genres.*' => ['string', 'max:255'],
            'metadata.tags' => ['array'], 'metadata.tags.*' => ['string', 'max:255'],
            'metadata.published_year' => ['nullable', 'string', 'regex:/^\d{4}$/'],
            'metadata.publisher' => ['nullable', 'string', 'max:255'], 'metadata.language' => ['nullable', 'string', 'max:255'],
            'metadata.isbn' => ['nullable', 'string', 'max:255'], 'metadata.asin' => ['nullable', 'string', 'max:255'],
            'metadata.abridged' => ['boolean'],
        ])->validate();

        return DB::transaction(function () use ($plan, $library, $user) {
            $id = self::sourceId('work', $plan['relative']);
            $work = Models\Work::withTrashed()->select(Models\Work::FIELDS)->where('abs_id', $id)->lockForUpdate()->first();
            if ($work) {
                if ($work->trashed() || $work->type !== 'book' || $work->user_id !== $user->id) {
                    throw new RuntimeException('此前导入的作品已删除、类型不符或导入账号不同，未覆盖原记录。');
                }

                return $work;
            }
            $library = Models\Library::query()->select(['id', 'type'])->lockForUpdate()->findOrFail($library->id);
            if ($library->type !== 'book') {
                throw new RuntimeException('目标分类必须为有声书类型。');
            }
            $work = Models\Work::create([
                ...$plan['attributes'], 'user_id' => $user->id, 'abs_library_id' => $library->id, 'type' => 'book',
                'abs_id' => $id, 'abs_media_id' => self::sourceId('book', $plan['relative']),
                'need_sync' => false, 'revision' => 0,
            ]);
            $authorIds = [];
            foreach ($plan['authors'] as $name) {
                Validator::make(['name' => $name], ['name' => ['required', 'string', 'max:80']])->validate();
                $author = Models\Author::query()->select(['id', 'name'])->firstOrCreate(['name' => $name], ['user_id' => $user->id]);
                $authorIds[] = $author->id;
            }
            $this->authors->assign($work, $authorIds);

            return $work;
        });
    }

    private function importAudio(int $workId, Models\User $user, array $file, int $season, int $episode, string $duplicates): array
    {
        Validator::make($file, [
            'relative' => ['required', 'string', 'max:255'], 'name' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
        ])->validate();
        $this->assertUnchanged($file);
        $hash = hash_file('sha256', $file['path']);
        if ($hash === false) {
            throw new RuntimeException('文件校验和读取失败。');
        }

        // Share the same hash lock as browser uploads, so a concurrent upload cannot publish another copy.
        return Cache::lock('audio-hash:'.$hash, 180)->block(10, function () use ($workId, $user, $file, $season, $episode, $duplicates, $hash) {
            $source = Models\Media::query()->select(['id', 'file_id'])
                ->whereHas('file', fn ($query) => $query->where(fn ($query) => $query->where('sha256', $hash)->orWhere('source_sha256', $hash)))
                ->orderBy('id')->first();
            if ($source && $duplicates === 'skip') {
                return ['status' => 'duplicates', 'bytes' => 0];
            }
            $information = $this->audioMetadata->probe($file['relative']);
            if (! is_finite((float) $information['duration']) || $information['duration'] <= 0) {
                throw new RuntimeException('音频没有有效时长。');
            }
            $this->assertUnchanged($file);

            return DB::transaction(function () use ($workId, $user, $file, $season, $episode, $hash, $source, $information) {
                $work = Models\Work::query()->select(Models\Work::FIELDS)->lockForUpdate()->findOrFail($workId);
                if (Models\Chapter::withTrashed()->where('abs_id', $file['id'])->exists()) {
                    return ['status' => 'existing', 'bytes' => 0];
                }
                if (Models\Chapter::query()->where('abs_list_id', $workId)->where('season_number', $season)->where('episode_number', $episode)->exists()) {
                    throw new RuntimeException('目标季／集编号刚被占用，请重新执行导入。');
                }
                if ($source) {
                    $stored = Models\StoredFile::query()->select(['id', 'file_size'])->lockForUpdate()->findOrFail($source->file_id);
                    if (! Models\Media::query()->whereKey($source->id)->exists()) {
                        throw new RuntimeException('重复音频刚刚下架，请重新执行导入。');
                    }
                } else {
                    $fileId = self::sourceId('file', $file['relative']);
                    if (Models\StoredFile::withTrashed()->whereKey($fileId)->exists()) {
                        throw new RuntimeException('原路径已有文件记录，未自动恢复或覆盖，请人工核对。');
                    }
                    $stored = Models\StoredFile::create([
                        'id' => $fileId, 'abs_list_id' => $workId, 'file_type' => 'audio', 'file_name' => $file['name'],
                        'file_size' => $file['size'], 'file_path' => $file['relative'], 'mime_type' => $this->audioMime($file),
                        'sha256' => $hash, 'source_sha256' => $hash, 'user_id' => $user->id,
                    ]);
                }
                $chapter = Models\Chapter::create([
                    'user_id' => $user->id, 'abs_list_id' => $workId, 'season_number' => $season, 'episode_number' => $episode,
                    'index' => ((int) Models\Chapter::query()->where('abs_list_id', $workId)->max('index')) + 1,
                    'title' => $file['title'], 'abs_id' => $file['id'],
                ]);
                $media = Models\Media::create([
                    'file_id' => $stored->id, 'abs_list_id' => $workId, 'abs_item_id' => $chapter->id, 'user_id' => $user->id,
                    'name' => $file['name'], 'file_size' => $stored->file_size, 'file_second' => $information['duration'],
                    'file_metadata' => $information['probe'], 'is_reference' => $source !== null,
                ]);
                $chapter->update(['primary_media_id' => $media->id]);
                $bytes = $source ? 0 : $file['size'];
                if ($bytes > 0) {
                    Models\User::query()->whereKey($user->id)->increment('size_upload', $bytes);
                }
                $this->markPending($work);

                return ['status' => $source ? 'referenced' : 'imported', 'bytes' => $bytes];
            });
        });
    }

    private function importCover(int $workId, Models\User $user, string $path): int
    {
        $relative = $this->relativePath($path);
        $id = self::sourceId('file', $relative);
        if (Models\Work::query()->whereKey($workId)->whereNotNull('cover_file_id')->exists() || Models\StoredFile::withTrashed()->whereKey($id)->exists()) {
            return 0;
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true) || ! @getimagesize($path)) {
            throw new RuntimeException('不是有效的封面图片：'.$relative);
        }
        Validator::make(['path' => $relative], ['path' => ['required', 'string', 'max:255']])->validate();
        $hash = hash_file('sha256', $path);
        $size = filesize($path);
        if ($hash === false || $size === false || $size <= 0) {
            throw new RuntimeException('封面读取失败。');
        }

        return DB::transaction(function () use ($workId, $user, $path, $relative, $id, $mime, $hash, $size) {
            $work = Models\Work::query()->select(Models\Work::FIELDS)->lockForUpdate()->findOrFail($workId);
            if ($work->cover_file_id || Models\StoredFile::withTrashed()->whereKey($id)->exists()) {
                return 0;
            }
            Models\StoredFile::create([
                'id' => $id, 'abs_list_id' => $workId, 'file_type' => 'cover', 'file_name' => basename($path),
                'file_size' => $size, 'file_path' => $relative, 'mime_type' => $mime,
                'sha256' => $hash, 'source_sha256' => $hash, 'user_id' => $user->id,
            ]);
            $work->cover_file_id = $id;
            Models\User::query()->whereKey($user->id)->increment('size_upload', $size);
            $this->markPending($work);

            return $size;
        });
    }

    private function markPending(Models\Work $work): void
    {
        // Keep the outbox debounce fresh without dispatching one queue job for every imported audio file.
        $work->forceFill(['revision' => $work->revision + 1, 'need_sync' => true, 'sync_status' => 'pending', 'sync_error' => null, 'sync_enqueued_at' => null])->save();
    }

    private function assertUnchanged(array $file): void
    {
        clearstatcache(true, $file['path']);
        if ($this->relativePath($file['path']) !== $file['relative'] || ! is_readable($file['path']) || filesize($file['path']) !== $file['size'] || filemtime($file['path']) !== $file['mtime']) {
            throw new RuntimeException('扫描后文件发生变化或不可读，请重新执行导入。');
        }
    }

    private function audioMime(array $file): string
    {
        return match (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION))) {
            'mp3' => 'audio/mpeg', 'm4a', 'm4b' => 'audio/mp4', 'aac' => 'audio/aac',
            'flac' => 'audio/flac', 'ogg', 'opus' => 'audio/ogg', 'wav' => 'audio/wav',
            'wma' => 'audio/x-ms-wma', 'aiff' => 'audio/aiff',
            default => throw new RuntimeException('不支持的音频格式。'),
        };
    }

    private function recordProblem(array &$result, string $path, string $message): void
    {
        Log::warning('makizhang import', ['path' => $path, 'error' => $message]);
        if (count($result['errors']) < 10) {
            $result['errors'][] = $path.'：'.mb_substr($message, 0, 300);
        }
    }
}
