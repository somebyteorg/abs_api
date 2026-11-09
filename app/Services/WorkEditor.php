<?php

namespace App\Services;

use App\Models;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkEditor
{
    public function __construct(
        private Activity $activity,
        private Authors $authors,
        private Chapters $chapters,
        private Covers $covers,
        private HistoryRestore $restore,
        private SyncScheduler $sync,
        private WorkMetadataRestore $metadata,
    ) {}

    public function create(Models\User $user, array $data): Models\Work
    {
        return DB::transaction(function () use ($user, $data) {
            $this->covers->validate($data['cover_file_id'] ?? null, $user);
            $library = $this->library($data['abs_library_id'], $data['type'], '作品类型和分类类型对不上。');
            $authorIds = $data['author_ids'] ?? [];
            unset($data['author_ids']);

            $work = Models\Work::create([
                ...$data,
                'user_id' => $user->id,
                'abs_id' => (string) Str::uuid(),
                'abs_media_id' => (string) Str::uuid(),
            ]);
            $this->authors->assign($work, $authorIds);
            $this->covers->attach($work->cover_file_id, $work->id);
            $this->activity->record($user, 'work.created', $work->name, $work->id, [
                'type' => $work->type,
                'library_id' => $library->id,
            ]);
            $this->sync->mark($work);

            return $work;
        });
    }

    public function update(Models\User $user, int $id, array $data): Models\Work
    {
        return DB::transaction(function () use ($user, $id, $data) {
            $work = $this->chapters->work($id);
            $this->covers->validate($data['cover_file_id'] ?? null, $user, $work->cover_file_id);
            if (isset($data['abs_library_id'])) {
                $this->library($data['abs_library_id'], $work->type, '创建后不能改类型，只能移动到同类型分类。');
            }

            $before = $this->metadata->capture($work, isset($data['author_ids']));
            $authorsChanged = isset($data['author_ids']) && $this->authors->assign($work, $data['author_ids']);
            unset($data['author_ids']);
            $work->fill($data);
            if (! $work->isDirty() && ! $authorsChanged) {
                return $work;
            }

            $details = $this->metadata->details($work, $before, $this->metadata->capture($work, $authorsChanged));
            $work->save();
            $this->covers->attach($work->cover_file_id, $work->id);
            $this->activity->record($user, 'work.updated', $work->name, $id, $details);
            $this->sync->mark($work);

            return $work;
        });
    }

    public function delete(Models\User $user, int $id, int $revision): void
    {
        DB::transaction(function () use ($user, $id, $revision) {
            $work = $this->chapters->work($id, $revision);
            abort_unless($work->user_id === $user->id || $user->canEditOthers(), 403, '只有作品创建者、编辑或管理员可以删除作品。');
            abort_if($work->chapters()->exists(), 422, '作品下还有章节，请先删除所有章节。');

            $work->delete();
            $this->sync->mark($work);
            $this->activity->record($user, 'work.deleted', $work->name, $id, [
                'creator_id' => $work->user_id,
                ...$this->restore->deletedWorkDetails($work),
            ]);
        });
    }

    public function reorder(Models\User $user, int $id, int $revision, array $ids): void
    {
        DB::transaction(function () use ($user, $id, $revision, $ids) {
            $work = $this->chapters->work($id, $revision);
            $chapters = Models\Chapter::query()
                ->select(['id', 'user_id', 'abs_list_id', 'title', 'season_number', 'episode_number', 'index', 'abs_id', 'slot_active'])
                ->where('abs_list_id', $id)->ordered()->get()->keyBy('id');
            $beforeIds = $chapters->keys()->all();
            $existing = $beforeIds;
            $incoming = $ids;
            sort($existing);
            sort($incoming);
            abort_unless($existing === $incoming, 409, '章节列表有变化，刷新后再排一次吧。');

            $snapshot = $this->restore->capture($beforeIds);
            $updates = [];
            foreach ($ids as $index => $chapterId) {
                $updates[] = [...$chapters[$chapterId]->getAttributes(), 'index' => $index + 1];
            }
            foreach (array_chunk($updates, 500) as $batch) {
                Models\Chapter::upsert($batch, ['id'], ['index', 'updated_at']);
            }
            $this->activity->record($user, 'chapters.reordered', $work->name, $id, [
                'before' => $beforeIds,
                'after' => $ids,
                ...$this->restore->details($snapshot, $this->restore->capture($beforeIds)),
            ]);
            $this->sync->mark($work);
        });
    }

    public function retrySync(Models\User $user, int $id): void
    {
        DB::transaction(function () use ($user, $id) {
            $work = $this->chapters->work($id);
            $this->sync->mark($work);
            $this->activity->record($user, 'sync.requested', $work->name, $id);
        });
    }

    private function library(int $id, string $type, string $message): Models\Library
    {
        $library = Models\Library::query()->select(['id', 'name', 'type'])->lockForUpdate()->findOrFail($id);
        abort_unless($library->type === $type, 422, $message);

        return $library;
    }
}
