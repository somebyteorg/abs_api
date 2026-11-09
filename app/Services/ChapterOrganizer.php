<?php

namespace App\Services;

use App\Models;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChapterOrganizer
{
    public function __construct(private Activity $activity, private SyncScheduler $sync, private HistoryRestore $restore) {}

    public function preview(Models\User $user, int $sourceId, array $command): array
    {
        $targetId = $command['action'] === 'move' ? (int) ($command['target_work_id'] ?? $sourceId) : $sourceId;
        $plan = DB::transaction(function () use ($sourceId, $targetId, $command) {
            $works = Models\Work::query()->select(['id', 'name', 'type', 'revision'])->whereIn('id', array_unique([$sourceId, $targetId]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($works->has($sourceId) && $works->has($targetId), 404, '没有找到目标作品。');
            abort_unless($works[$sourceId]->type === $works[$targetId]->type, 422, '资源只能移动到相同类型的作品。');
            $chapters = Models\Chapter::query()->select(Models\Chapter::FIELDS)->whereIn('abs_list_id', $works->keys())->get()->keyBy('id');
            $slots = $chapters->keyBy(fn ($chapter) => $chapter->abs_list_id.':'.$chapter->season_number.':'.$chapter->episode_number);
            $base = ['file_ids' => [], 'action' => $command['action'], 'source_id' => $sourceId, 'versions' => $works->map(fn ($work) => $work->revision)->all(), 'moves' => [], 'edits' => [], 'merge_ids' => [], 'new_chapters' => [], 'groups' => [], 'changes' => []];
            if ($command['action'] === 'edit') {
                return $this->editPlan($base, $command['rows'], $chapters, $sourceId);
            }

            $new = [];
            $moves = [];
            $mergeIds = [];
            $affected = [];
            $resolve = function (array $row, Models\Chapter $source) use ($chapters, $slots, $targetId, &$new): string {
                if (! empty($row['chapter_id'])) {
                    $target = $chapters->get((int) $row['chapter_id']);
                    abort_unless($target && $target->abs_list_id === $targetId, 422, '目标章节不属于所选作品。');

                    return 'e:'.$target->id;
                }
                abort_unless(isset($row['season_number'], $row['episode_number']), 422, '新章节必须填写季数和集数。');
                $season = (int) $row['season_number'];
                $episode = (int) $row['episode_number'];
                $existing = $slots->get($targetId.':'.$season.':'.$episode);
                if ($existing) {
                    return 'e:'.$existing->id;
                }
                $key = 'n:'.$targetId.':'.$season.':'.$episode;
                $title = trim($row['title'] ?? '') ?: $source->title;
                abort_if(isset($new[$key]) && $new[$key]['title'] !== $title, 422, '同一目标季／集填了不同的标题，请核对后再预览。');
                $new[$key] = ['work_id' => $targetId, 'title' => $title, 'season_number' => $season, 'episode_number' => $episode];

                return $key;
            };
            if ($command['action'] === 'merge') {
                $target = $chapters->get((int) $command['target_chapter_id']);
                abort_unless($target && $target->abs_list_id === $sourceId, 422, '合并目标必须是当前作品的章节。');
                $mergeIds = array_values(array_diff(array_map('intval', $command['chapter_ids']), [$target->id]));
                abort_unless($mergeIds, 422, '至少选择一个需要合并到目标的章节。');
                foreach ($mergeIds as $id) {
                    abort_unless($chapters->has($id) && $chapters[$id]->abs_list_id === $sourceId, 422, '所选章节已发生变化。');
                }
                $resources = Models\Media::query()->select(Models\Media::FIELDS)->whereIn('abs_item_id', $mergeIds)->get();
                foreach ($resources as $resource) {
                    $moves[$resource->id] = 'e:'.$target->id;
                }
                $affected = [...$mergeIds, $target->id];
            } else {
                $ids = array_column($command['rows'], 'media_id');
                $resources = Models\Media::query()->select(Models\Media::FIELDS)->where('abs_list_id', $sourceId)->whereIn('id', $ids)->get()->keyBy('id');
                abort_unless($resources->count() === count($ids), 409, '部分资源已移动或删除，请刷新列表。');
                foreach ($command['rows'] as $row) {
                    $resource = $resources[(int) $row['media_id']];
                    $source = $chapters->get($resource->abs_item_id);
                    abort_unless($source, 409, '原章节已经发生变化。');
                    $key = $resolve($row, $source);
                    if ($key === 'e:'.$source->id) {
                        continue;
                    }
                    $moves[$resource->id] = $key;
                    $affected[] = $source->id;
                    if (str_starts_with($key, 'e:')) {
                        $affected[] = (int) substr($key, 2);
                    }
                }
                abort_unless($moves, 422, '没有需要移动的资源，请选择不同的目标章节。');
            }
            $affected = array_values(array_unique($affected));
            $groups = [];
            foreach ($affected as $id) {
                if (in_array($id, $mergeIds, true)) {
                    continue;
                }
                $chapter = $chapters[$id];
                $groups['e:'.$id] = ['key' => 'e:'.$id, 'chapter_id' => $id, 'work_id' => $chapter->abs_list_id, 'title' => $chapter->title, 'season_number' => $chapter->season_number, 'episode_number' => $chapter->episode_number, 'primary_media_id' => $chapter->primary_media_id, 'resources' => [], 'incoming' => false];
            }
            foreach ($new as $key => $spec) {
                $groups[$key] = ['key' => $key, 'chapter_id' => null, ...$spec, 'primary_media_id' => null, 'resources' => [], 'incoming' => true];
            }
            $all = Models\Media::query()->select(Models\Media::FIELDS)->whereIn('abs_item_id', $affected)->with('uploader:id,username,pseudonym')->get();
            foreach ($all as $resource) {
                $key = $moves[$resource->id] ?? 'e:'.$resource->abs_item_id;
                if (! isset($groups[$key])) {
                    continue;
                }
                $groups[$key]['resources'][] = ['id' => $resource->id, 'name' => $resource->name, 'file_size' => $resource->file_size, 'uploader_name' => $resource->uploader->display_name, 'source_chapter' => $this->label($chapters[$resource->abs_item_id]), 'incoming' => isset($moves[$resource->id])];
                if (isset($moves[$resource->id])) {
                    $groups[$key]['incoming'] = true;
                    $source = $chapters[$resource->abs_item_id];
                    $target = $groups[$key];
                    $base['changes'][] = ['kind' => 'resource', 'id' => $resource->id, 'name' => $resource->name, 'from' => $works[$source->abs_list_id]->name.' / '.$this->label($source), 'to' => $works[$target['work_id']]->name.' / '.$this->label($target)];
                }
            }
            foreach ($groups as &$group) {
                $ids = array_column($group['resources'], 'id');
                $group['work_name'] = $works[$group['work_id']]->name;
                $group['previous_primary_id'] = $group['primary_media_id'];
                $group['requires_choice'] = count($ids) > 1 && ($group['incoming'] || ! in_array($group['primary_media_id'], $ids, true));
                if (count($ids) <= 1) {
                    $group['primary_media_id'] = $ids[0] ?? null;
                }
                if ($group['requires_choice']) {
                    $group['primary_media_id'] = null;
                }
            }
            unset($group);
            foreach ($mergeIds as $id) {
                $base['changes'][] = ['kind' => 'chapter', 'id' => $id, 'name' => $chapters[$id]->title, 'from' => $this->label($chapters[$id]), 'to' => '合并后保留为历史记录'];
            }

            return [...$base, 'file_ids' => $all->pluck('file_id', 'id')->all(), 'moves' => $moves, 'groups' => $groups, 'new_chapters' => $new, 'merge_ids' => $mergeIds];
        });
        $token = (string) Str::uuid();
        Cache::put('chapter-plan:'.$token, [...$plan, 'user_id' => $user->id], now()->addMinutes(15));

        return ['token' => $token, 'action' => $plan['action'], 'changes' => $plan['changes'], 'groups' => array_values(array_filter($plan['groups'], fn ($group) => $group['requires_choice'])), 'expires_in' => 900];
    }

    private function label(Models\Chapter|array $chapter): string
    {
        return 'S'.$chapter['season_number'].'E'.$chapter['episode_number'].' '.$chapter['title'];
    }

    private function editPlan(array $plan, array $rows, $chapters, int $workId): array
    {
        $edits = [];
        foreach ($rows as $row) {
            $id = (int) ($row['chapter_id'] ?? 0);
            $chapter = $chapters->get($id);
            abort_unless($chapter && $chapter->abs_list_id === $workId && ! isset($edits[$id]), 422, '批量章节编号无效或重复。');
            $title = trim($row['title'] ?? $chapter->title);
            abort_if($title === '', 422, '章节标题不能为空。');
            $next = ['title' => $title, 'season_number' => (int) ($row['season_number'] ?? $chapter->season_number), 'episode_number' => (int) ($row['episode_number'] ?? $chapter->episode_number)];
            $edits[$id] = $next;
            if ($next !== $chapter->only(['title', 'season_number', 'episode_number'])) {
                $plan['changes'][] = ['kind' => 'chapter', 'id' => $id, 'name' => $chapter->title, 'from' => $this->label($chapter), 'to' => $this->label($next)];
            }
        }
        $slots = [];
        foreach ($chapters->where('abs_list_id', $workId) as $chapter) {
            $next = $edits[$chapter->id] ?? $chapter;
            $key = $next['season_number'].':'.$next['episode_number'];
            abort_if(isset($slots[$key]), 422, '修改后有重复的季／集编号，请调整整批编号。');
            $slots[$key] = true;
        }
        abort_unless($plan['changes'], 422, '没有需要修改的章节。');

        return [...$plan, 'edits' => $edits];
    }

    public function apply(Models\User $user, int $sourceId, string $token, array $primaries): array
    {
        return Cache::lock('chapter-plan-lock:'.$token, 120)->block(3, function () use ($user, $sourceId, $token, $primaries) {
            $key = 'chapter-plan:'.$token;
            $plan = Cache::get($key);
            abort_unless($plan && $plan['user_id'] === $user->id && $plan['source_id'] === $sourceId, 410, '预览已过期，请重新生成。');
            if (isset($plan['result'])) {
                return $plan['result'];
            }
            $result = DB::transaction(function () use ($user, $plan, $primaries) {
                $works = Models\Work::query()->select(['id', 'name', 'type', 'revision'])->whereIn('id', array_keys($plan['versions']))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                foreach ($plan['versions'] as $id => $revision) {
                    abort_unless($works->has($id) && $works[$id]->revision === $revision, 409, '预览后作品有新改动，请刷新并重新预览。');
                }
                foreach ($plan['groups'] as $key => &$group) {
                    if ($group['requires_choice']) {
                        $choice = (int) ($primaries[$key] ?? 0);
                        abort_unless(in_array($choice, array_column($group['resources'], 'id'), true), 422, '请为每个有多份资源的章节选择主资源。');
                        $group['primary_media_id'] = $choice;
                    }
                }
                unset($group);
                $chapterIds = array_values(array_unique([...array_keys($plan['edits']), ...$plan['merge_ids'], ...array_filter(array_column($plan['groups'], 'chapter_id'))]));
                $resourceIds = array_keys($plan['moves']);
                $snapshot = $this->restore->capture($chapterIds, $resourceIds);
                $targets = [];
                $newRows = [];
                $identities = [];
                $nextIndex = [];
                foreach ($plan['new_chapters'] as $key => $spec) {
                    $workId = $spec['work_id'];
                    if (! isset($nextIndex[$workId])) {
                        $nextIndex[$workId] = (int) Models\Chapter::query()->where('abs_list_id', $workId)->max('index');
                    }
                    $uuid = (string) Str::uuid();
                    $identities[$key] = $uuid;
                    $newRows[] = ['user_id' => $user->id, 'abs_list_id' => $workId, 'title' => $spec['title'], 'season_number' => $spec['season_number'], 'episode_number' => $spec['episode_number'], 'index' => ++$nextIndex[$workId], 'abs_id' => $uuid, 'primary_media_id' => null, 'created_at' => now(), 'updated_at' => now()];
                }
                foreach (array_chunk($newRows, 500) as $batch) {
                    Models\Chapter::query()->insert($batch);
                }
                if ($identities) {
                    $created = Models\Chapter::query()->select(Models\Chapter::FIELDS)->whereIn('abs_id', array_values($identities))->get()->keyBy('abs_id');
                    foreach ($identities as $key => $uuid) {
                        $targets[$key] = $created[$uuid];
                    }
                }
                if ($plan['edits']) {
                    $records = Models\Chapter::query()->select(Models\Chapter::FIELDS)->whereIn('id', array_keys($plan['edits']))->get();
                    Models\Chapter::query()->whereIn('id', $records->modelKeys())->update(['slot_active' => null]);
                    $rows = $records->map(fn ($chapter) => [...$chapter->getAttributes(), ...$plan['edits'][$chapter->id], 'slot_active' => true])->all();
                    foreach (array_chunk($rows, 500) as $batch) {
                        Models\Chapter::query()->upsert($batch, ['id'], ['title', 'season_number', 'episode_number', 'slot_active', 'updated_at']);
                    }
                }
                $byTarget = [];
                foreach ($plan['moves'] as $id => $target) {
                    $byTarget[$target][] = (int) $id;
                }
                foreach ($byTarget as $target => $ids) {
                    $group = $plan['groups'][$target];
                    $chapterId = $group['chapter_id'] ?? $targets[$target]->id;
                    Models\Media::query()->whereIn('id', $ids)->update(['abs_item_id' => $chapterId, 'abs_list_id' => $group['work_id']]);
                }
                $primaryByChapter = [];
                foreach ($plan['groups'] as $key => $group) {
                    $primaryByChapter[$group['chapter_id'] ?? $targets[$key]->id] = $group['primary_media_id'];
                }
                if ($primaryByChapter) {
                    $records = Models\Chapter::query()->select(Models\Chapter::FIELDS)->whereIn('id', array_keys($primaryByChapter))->get();
                    $rows = $records->map(fn ($chapter) => [...$chapter->getAttributes(), 'primary_media_id' => $primaryByChapter[$chapter->id]])->all();
                    foreach (array_chunk($rows, 500) as $batch) {
                        Models\Chapter::query()->upsert($batch, ['id'], ['primary_media_id', 'updated_at']);
                    }
                }
                if ($plan['merge_ids']) {
                    Models\Chapter::query()->whereIn('id', $plan['merge_ids'])->update(['primary_media_id' => null, 'slot_active' => null, 'merged_into_id' => (int) ($plan['groups'][array_key_first($plan['groups'])]['chapter_id'])]);
                    Models\Chapter::query()->whereIn('id', $plan['merge_ids'])->delete();
                }
                $primarySelections = [];
                foreach ($plan['groups'] as $group) {
                    if (! $group['requires_choice']) {
                        continue;
                    }
                    $name = collect($group['resources'])->firstWhere('id', $group['primary_media_id'])['name'];
                    $primarySelections[] = ['chapter' => $group['title'], 'resource' => $name];
                }
                $action = ['move' => 'resources.moved', 'merge' => 'chapters.merged', 'edit' => 'chapters.edited'][$plan['action']];
                $restoreDetails = $this->restore->details($snapshot, $this->restore->capture([...$chapterIds, ...array_map(fn ($chapter) => $chapter->id, array_values($targets))], $resourceIds));
                foreach ($works as $work) {
                    $this->activity->record($user, $action, $work->name, $work->id, ['changes' => $plan['changes'], 'primary_choices' => $primaries, 'primary_selections' => $primarySelections, ...$restoreDetails]);
                    $this->sync->mark($work);
                }

                return ['message' => '整理已完成。', 'changed' => count($plan['changes'])];
            });
            Cache::put($key, [...$plan, 'result' => $result], now()->addMinutes(15));

            return $result;
        });
    }
}
