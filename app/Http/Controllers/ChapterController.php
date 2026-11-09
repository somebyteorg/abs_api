<?php

namespace App\Http\Controllers;

use App\Models;
use App\Services;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ChapterController extends Controller
{
    public function index(Request $request, int $work)
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        Models\Work::query()->select(['id'])->findOrFail($work);
        $page = Models\Chapter::query()->select(['id', 'title', 'season_number', 'episode_number'])->where('abs_list_id', $work)
            ->matching($data['q'] ?? null)->ordered()->paginate(Models\Chapter::PAGE_SIZE);

        return $this->FormatPage($page, $page->items());
    }

    public function directory(int $work)
    {
        $entry = Models\Work::query()->select(['id', 'revision'])->findOrFail($work);
        $chapters = Models\Chapter::query()->select(['id', 'abs_list_id', 'title', 'season_number', 'episode_number', 'index', 'primary_media_id'])->where('abs_list_id', $work)->ordered()->get();

        return ['revision' => $entry->revision, 'items' => $chapters];
    }

    private function fields(bool $partial = false): array
    {
        return [
            'title' => [$partial ? 'sometimes' : 'required', 'required', 'string', 'max:255'],
            'season_number' => [$partial ? 'sometimes' : 'required', 'integer', 'min:0', 'max:1000000'],
            'episode_number' => [$partial ? 'sometimes' : 'required', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    public function store(Request $request, int $work, Services\Chapters $chapters, Services\Activity $activity, Services\SyncScheduler $sync, Services\HistoryRestore $restore)
    {
        $data = $request->validate(['expected_revision' => ['required', 'integer', 'min:0'], ...$this->fields()]);

        return DB::transaction(function () use ($request, $work, $data, $chapters, $activity, $sync, $restore) {
            $entry = $chapters->work($work, $data['expected_revision']);
            $chapter = $chapters->create($entry, $request->user(), $data);
            $activity->record($request->user(), 'chapter.created', $chapter->title, $work, [...$chapter->only(['id', 'season_number', 'episode_number']), ...$restore->details($restore->capture([]), $restore->capture([$chapter->id]))]);
            $sync->mark($entry);

            return response()->json(['data' => $chapter], 201);
        });
    }

    public function update(Request $request, int $work, int $chapter, Services\Chapters $chapters, Services\Activity $activity, Services\SyncScheduler $sync, Services\HistoryRestore $restore)
    {
        $data = $request->validate(['expected_revision' => ['required', 'integer', 'min:0'], ...$this->fields(true)]);

        return DB::transaction(function () use ($request, $work, $chapter, $data, $chapters, $activity, $sync, $restore) {
            $entry = $chapters->work($work, $data['expected_revision']);
            $row = $chapters->find($work, $chapter);
            $before = $row->only(['title', 'season_number', 'episode_number']);
            $after = array_replace($before, array_intersect_key($data, $before));
            abort_if(Models\Chapter::query()->where('abs_list_id', $work)->whereKeyNot($chapter)->where('season_number', $after['season_number'])->where('episode_number', $after['episode_number'])->exists(), 409, '这个季／集编号已被占用；整批错位可以用批量整理修正。');
            $row->fill($after);
            if ($row->isDirty()) {
                $snapshot = $restore->capture([$chapter]);
                $row->save();
                $activity->record($request->user(), 'chapter.updated', $row->title, $work, ['chapter_id' => $chapter, 'before' => $before, 'after' => $after, ...$restore->details($snapshot, $restore->capture([$chapter]))]);
                $sync->mark($entry);
            }

            return ['data' => $row];
        });
    }

    public function primary(Request $request, int $work, int $chapter, Services\Chapters $chapters, Services\Activity $activity, Services\SyncScheduler $sync, Services\HistoryRestore $restore)
    {
        $data = $request->validate(['media_id' => ['required', 'integer'], 'expected_revision' => ['required', 'integer', 'min:0']]);

        return DB::transaction(function () use ($request, $work, $chapter, $data, $chapters, $activity, $sync, $restore) {
            $entry = $chapters->work($work, $data['expected_revision']);
            $row = $chapters->find($work, $chapter);
            $chosen = Models\Media::query()->select(['id', 'name'])->where('abs_item_id', $chapter)->findOrFail($data['media_id']);
            $before = $row->primary_media_id;
            $beforeName = $before ? Models\Media::query()->whereKey($before)->value('name') : null;
            $snapshot = $restore->capture([$chapter]);
            $row->update(['primary_media_id' => $data['media_id']]);
            $activity->record($request->user(), 'primary.changed', $row->title, $work, ['chapter_id' => $chapter, 'before' => $before, 'after' => $data['media_id'], 'resource_names' => ['before' => $beforeName, 'after' => $chosen->name], ...$restore->details($snapshot, $restore->capture([$chapter]))]);
            $sync->mark($entry);

            return ['message' => '主资源已更新。'];
        });
    }

    public function destroy(Request $request, int $work, int $chapter, Services\Chapters $chapters, Services\Activity $activity, Services\SyncScheduler $sync, Services\HistoryRestore $restore)
    {
        $data = $request->validate(['expected_revision' => ['required', 'integer', 'min:0']]);

        return DB::transaction(function () use ($request, $work, $chapter, $data, $chapters, $activity, $sync, $restore) {
            $entry = $chapters->work($work, $data['expected_revision']);
            $row = $chapters->find($work, $chapter);
            abort_if($row->resources()->exists(), 422, '章节还有资源，请先移动资源或合并章节。');
            $snapshot = $restore->capture([$chapter]);
            $row->update(['primary_media_id' => null, 'slot_active' => null]);
            $row->delete();
            $activity->record($request->user(), 'chapter.deleted', $row->title, $work, ['chapter_id' => $chapter, ...$restore->details($snapshot, $restore->capture([$chapter]))]);
            $sync->mark($entry);

            return ['message' => '空章节已移除，记录仍会保留。'];
        });
    }

    public function preview(Request $request, int $work, Services\ChapterOrganizer $organizer)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['move', 'merge', 'edit'])],
            'target_work_id' => ['nullable', 'integer'], 'target_chapter_id' => ['required_if:action,merge', 'nullable', 'integer'],
            'chapter_ids' => ['required_if:action,merge', 'array', 'max:2000'], 'chapter_ids.*' => ['integer', 'distinct'],
            'rows' => ['required_unless:action,merge', 'array', 'min:1', 'max:2000'],
            'rows.*.media_id' => ['required_if:action,move', 'integer', 'distinct'],
            'rows.*.chapter_id' => ['nullable', 'integer'],
            'rows.*.title' => ['nullable', 'string', 'max:255'],
            'rows.*.season_number' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'rows.*.episode_number' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);

        return $organizer->preview($request->user(), $work, $data);
    }

    public function apply(Request $request, int $work, string $plan, Services\ChapterOrganizer $organizer)
    {
        $data = $request->validate(['primaries' => ['present', 'array'], 'primaries.*' => ['integer']]);

        return $organizer->apply($request->user(), $work, $plan, $data['primaries']);
    }
}
