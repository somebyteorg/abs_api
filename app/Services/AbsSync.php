<?php

namespace App\Services;

use App\Models;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use RuntimeException;

class AbsSync
{
    public function sync(int $workId): void
    {
        // All target primary keys come from the API. Native table/FK errors are handled by the queue.
        DB::transaction(function () use ($workId) {
            // All API mutations take this same lock, so an older snapshot cannot overwrite a newer one.
            $work = Models\Work::withTrashed()->select(Models\Work::FIELDS)->lockForUpdate()->findOrFail($workId);
            if ($work->trashed()) {
                $this->withdraw($work);

                return;
            }
            $chapters = Models\Chapter::query()->select(Models\Chapter::FIELDS)->where('abs_list_id', $workId)->orderBy('index')->orderBy('id')
                ->whereHas('primaryMedia', fn ($q) => $q->whereHas('file'))
                ->with(['primaryMedia' => fn ($q) => $q->select([...Models\Media::FIELDS, 'file_metadata'])->with([
                    'file:id,file_name,file_path,file_size,mime_type,created_at,updated_at',
                    'uploader:id,username,pseudonym',
                ])])->get();
            if ($chapters->isEmpty()) {
                $this->withdraw($work);

                return;
            }
            $library = Models\Library::query()->select(Models\Library::FIELDS)->lockForUpdate()->findOrFail($work->abs_library_id);
            $work->load(['coverFile:id,file_path', 'authors' => fn ($q) => $q->select(['abs_authors.id', 'name', 'description', 'cover_file_id'])->with('coverFile:id,file_path')]);
            DB::connection('abs')->transaction(function () use ($work, $library, $chapters) {
                $metadata = $work->metadata;
                $this->library($library);
                $authorNames = $work->authors->pluck('name')->implode(', ');
                $files = [];
                $bookChapters = [];
                $duration = 0.0;
                $episodes = [];
                foreach ($chapters as $chapter) {
                    if (! $chapter->primaryMedia || $chapter->primaryMedia->abs_item_id !== $chapter->id || $chapter->primaryMedia->abs_list_id !== $work->id) {
                        throw new RuntimeException('主资源与章节或作品不匹配，请先整理资源。');
                    }
                    $audio = $this->audioFile($chapter->primaryMedia, count($files) + 1);
                    $files[] = $audio;
                    $bookChapters[] = ['id' => count($bookChapters), 'start' => $duration, 'end' => $duration + $audio['duration'], 'title' => $chapter->title];
                    $duration += $audio['duration'];
                    if ($work->type === 'podcast') {
                        $episodes[$chapter->abs_id] = [
                            'podcastid' => $work->abs_media_id, 'index' => $chapter->index,
                            'season' => (string) $chapter->season_number, 'episode' => (string) $chapter->episode_number,
                            'episodetype' => 'full', 'title' => $chapter->title, 'audiofile' => $audio,
                            'chapters' => $audio['chapters'], 'publishedat' => $chapter->primaryMedia->created_at,
                            'enclosuresize' => $chapter->primaryMedia->file_size, 'enclosuretype' => $audio['mimeType'],
                            'extradata' => ['emosChapterId' => $chapter->id, 'withdrawn' => false],
                        ];
                    }
                }
                $common = ['title' => $work->name, 'titleignoreprefix' => $work->name, 'description' => $work->description, 'explicit' => $work->is_adult, 'coverpath' => $work->coverFile?->absPath(), 'tags' => $metadata['tags'] ?? [], 'genres' => $metadata['genres'] ?? []];
                if ($work->type === 'book') {
                    $this->write(Models\Abs\Book::class, $work->abs_media_id, [...$common,
                        'subtitle' => $work->tagline,
                        'publishedyear' => $work->date_air?->format('Y') ?? $metadata['published_year'] ?? null, 'publisheddate' => $work->date_air?->format('Y-m-d'),
                        'publisher' => $metadata['publisher'] ?? null, 'language' => $metadata['language'] ?? null, 'isbn' => $metadata['isbn'] ?? null, 'asin' => $metadata['asin'] ?? null,
                        'audiofiles' => $files, 'chapters' => $bookChapters, 'duration' => $duration, 'narrators' => $metadata['narrators'] ?? [], 'abridged' => $metadata['abridged'] ?? false,
                    ]);
                    $this->authors($work, $library);
                } else {
                    $this->write(Models\Abs\Podcast::class, $work->abs_media_id, [...$common, 'releasedate' => $work->date_air?->format('Y-m-d'),
                        'author' => $authorNames, 'numepisodes' => count($files), 'autodownloadepisodes' => false,
                    ]);
                }
                $activeEpisodes = [];
                foreach ($episodes as $episodeId => $attributes) {
                    $activeEpisodes[] = ['id' => $episodeId, ...$attributes];
                }
                Models\Abs\PodcastEpisode::syncById($activeEpisodes);
                if ($work->type === 'podcast') {
                    Models\Abs\PodcastEpisode::query()->where('podcastid', $work->abs_media_id)->whereNotIn('id', array_keys($episodes))
                        ->update(['podcastid' => null, 'extradata->withdrawn' => true]);
                }
                $this->write(Models\Abs\LibraryItem::class, $work->abs_id, [
                    'libraryid' => $library->abs_id, 'libraryfolderid' => $library->abs_folder_id,
                    'mediaid' => $work->abs_media_id, 'mediatype' => $work->type,
                    'ino' => $work->abs_id, 'path' => $work->abs_id, 'relpath' => $work->abs_id,
                    'isfile' => false, 'ismissing' => false, 'isinvalid' => false,
                    'mtime' => $work->updated_at, 'ctime' => $work->created_at, 'birthtime' => $work->created_at,
                    'size' => array_sum(array_column(array_column($files, 'metadata'), 'size')),
                    'libraryfiles' => [], 'title' => $work->name, 'titleignoreprefix' => $work->name,
                    'authornamesfirstlast' => $authorNames, 'authornameslastfirst' => $authorNames,
                    'extradata' => ['emosWorkId' => $work->id, 'fileAccess' => 'storage', 'withdrawn' => false],
                ]);
            });
            $library->timestamps = false;
            $library->update(['need_sync' => false]);
            $this->synced($work);
        });
    }

    private function withdraw(Models\Work $work): void
    {
        DB::connection('abs')->transaction(function () use ($work) {
            if ($work->type === 'podcast') {
                Models\Abs\PodcastEpisode::query()->where('podcastid', $work->abs_media_id)->update(['podcastid' => null, 'extradata->withdrawn' => true]);
            }
            Models\Abs\LibraryItem::query()->whereKey($work->abs_id)->delete();
            if ($work->type === 'book') {
                Models\Abs\BookAuthor::query()->where('bookid', $work->abs_media_id)->delete();
                Models\Abs\Book::query()->whereKey($work->abs_media_id)->delete();
            } else {
                Models\Abs\Podcast::query()->whereKey($work->abs_media_id)->delete();
            }
        });
        $this->synced($work);
    }

    private function synced(Models\Work $work): void
    {
        $work->timestamps = false;
        $work->forceFill(['need_sync' => false, 'sync_status' => 'synced', 'sync_error' => null, 'synced_at' => now()])->save();
    }

    public function syncLibrary(int $libraryId): void
    {
        DB::transaction(function () use ($libraryId) {
            $library = Models\Library::withTrashed()->select(Models\Library::FIELDS)->lockForUpdate()->findOrFail($libraryId);
            DB::connection('abs')->transaction(function () use ($library) {
                if (! $library->trashed()) {
                    $this->library($library);

                    return;
                }
                if (Models\Abs\LibraryItem::query()->where('libraryid', $library->abs_id)->exists()) {
                    throw new RuntimeException('ABS 分类仍有作品，等待作品移动同步完成后再删除空分类。');
                }
                Models\Abs\LibraryFolder::query()->where('libraryid', $library->abs_id)->delete();
                Models\Abs\Library::query()->whereKey($library->abs_id)->delete();
            });
            $library->timestamps = false;
            $library->update(['need_sync' => false]);
        });
    }

    private function library(Models\Library $library): void
    {
        $this->write(Models\Abs\Library::class, $library->abs_id, [
            'name' => $library->name, 'mediatype' => $library->type, 'displayorder' => $library->id,
            'icon' => $library->type === 'book' ? 'audiobooks' : 'podcast', 'provider' => 'google',
            'settings' => ['coverAspectRatio' => 1, 'disableWatcher' => true, 'autoScanCronExpression' => null, 'metadataPrecedence' => [], 'markAsFinishedTimeRemaining' => 10],
            'extradata' => ['emosLibraryId' => $library->id],
        ]);
        $this->write(Models\Abs\LibraryFolder::class, $library->abs_folder_id, ['libraryid' => $library->abs_id, 'path' => $library->abs_id]);
    }

    private function authors(Models\Work $work, Models\Library $library): void
    {
        $mapped = Models\AuthorLibrary::query()->select(['id', 'author_id'])->where('abs_library_id', $library->id)->whereIn('author_id', $work->authors->modelKeys())->get()->keyBy('author_id');
        $authors = [];
        $joins = [];
        foreach ($work->authors as $author) {
            $link = $mapped->get($author->id);
            if (! $link) {
                $id = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'emos-abs-author:'.$author->id.':'.$library->abs_id);
                $link = Models\AuthorLibrary::query()->select(['id', 'author_id'])->firstOrCreate(['author_id' => $author->id, 'abs_library_id' => $library->id], ['id' => $id]);
            }
            $authors[] = ['id' => $link->id, 'libraryid' => $library->abs_id, 'name' => $author->name, 'lastfirst' => $author->name, 'description' => $author->description, 'imagepath' => $author->coverFile?->absPath()];
            $joins[] = ['id' => $author->pivot->abs_id, 'bookid' => $work->abs_media_id, 'authorid' => $link->id, 'createdat' => $author->pivot->created_at->toIso8601String()];
        }
        Models\Abs\Author::syncById($authors);
        Models\Abs\BookAuthor::query()->where('bookid', $work->abs_media_id)->whereNotIn('id', array_column($joins, 'id'))->delete();
        foreach (array_chunk($joins, 500) as $rows) {
            Models\Abs\BookAuthor::query()->upsert($rows, ['id'], ['bookid', 'authorid']);
        }
    }

    private function write(string $model, string $id, array $attributes): void
    {
        $model::syncById([['id' => $id, ...$attributes]]);
    }

    private function audioFile(Models\Media $media, int $index): array
    {
        if (! $media->file) {
            throw new RuntimeException('音频文件记录缺失，无法同步。');
        }
        if (! $media->uploader) {
            throw new RuntimeException('上传者记录缺失，无法同步音轨标题。');
        }
        $probe = $media->file_metadata;
        $stream = collect($probe['streams'] ?? [])->first(fn ($s) => ($s['codec_type'] ?? null) === 'audio') ?? [];
        $extension = strtolower(pathinfo($media->file->file_name, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'mp3' => 'audio/mpeg',
            'm4a', 'm4b' => 'audio/mp4',
            'flac' => 'audio/flac',
            'ogg', 'opus' => 'audio/ogg',
            'wav' => 'audio/wav',
            'aac' => 'audio/aac',
            default => $media->file->mime_type,
        };
        $chapters = [];
        foreach ($probe['chapters'] ?? [] as $chapter) {
            $start = (float) ($chapter['start_time'] ?? 0);
            $end = (float) ($chapter['end_time'] ?? 0);
            if ($end > $start && $start >= 0 && $end <= $media->file_second + 1) {
                $chapters[] = ['id' => count($chapters), 'start' => $start, 'end' => $end, 'title' => (string) ($chapter['tags']['title'] ?? '章节 '.(count($chapters) + 1))];
            }
        }

        return [
            'index' => $index, 'ino' => $media->file_id,
            'metadata' => ['filename' => $media->name.' - '.$media->uploader->display_name, 'ext' => '.'.$extension, 'path' => $media->file->absPath(), 'relPath' => null,
                'size' => $media->file_size, 'mtimeMs' => $media->file->updated_at->getTimestampMs(), 'ctimeMs' => $media->file->created_at->getTimestampMs(), 'birthtimeMs' => $media->file->created_at->getTimestampMs()],
            'addedAt' => $media->created_at->getTimestampMs(), 'updatedAt' => $media->created_at->getTimestampMs(),
            'trackNumFromMeta' => null, 'discNumFromMeta' => null, 'trackNumFromFilename' => $index, 'discNumFromFilename' => null,
            'manuallyVerified' => true, 'exclude' => false, 'error' => null,
            'format' => $probe['format']['format_long_name'] ?? $extension, 'duration' => $media->file_second,
            'bitRate' => isset($stream['bit_rate']) ? (int) $stream['bit_rate'] : null, 'language' => $stream['tags']['language'] ?? null,
            'codec' => $stream['codec_name'] ?? null, 'timeBase' => $stream['time_base'] ?? null,
            'channels' => $stream['channels'] ?? null, 'channelLayout' => $stream['channel_layout'] ?? null,
            'chapters' => $chapters, 'embeddedCoverArt' => null, 'metaTags' => [], 'mimeType' => $mime,
        ];
    }
}
