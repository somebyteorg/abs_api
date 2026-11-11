<?php

namespace App\Services;

use App\Models;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class HistoryRestore
{
    private const CHAPTER = ['id', 'abs_list_id', 'title', 'season_number', 'episode_number', 'index', 'primary_media_id', 'slot_active', 'merged_into_id', 'deleted_at'];

    private const RESOURCE = ['id', 'abs_list_id', 'abs_item_id', 'file_id', 'name', 'deleted_at', 'delete_reason'];

    private const FILE = ['id', 'abs_list_id', 'deleted_at'];

    private const MODELS = ['chapters' => Models\Chapter::class, 'resources' => Models\Media::class, 'files' => Models\StoredFile::class];

    private const FIELDS = ['chapters' => self::CHAPTER, 'resources' => self::RESOURCE, 'files' => self::FILE];

    public function __construct(private Activity $activity, private SyncScheduler $sync, private WorkMetadataRestore $metadata, private EntityRestore $entities) {}

    public function deletedWorkDetails(Models\Work $work): array
    {
        return ['restore' => ['id' => (string) Str::uuid(), 'kind' => 'work', 'work_ids' => [$work->id], 'revision' => $work->revision, 'deleted_at' => $work->deleted_at->toIso8601String()]];
    }

    public function capture(array $chapterIds, array $resourceIds = []): array
    {
        $chapters = $chapterIds ? Models\Chapter::withTrashed()->select(self::CHAPTER)->whereIn('id', $chapterIds)->get() : collect();
        $resources = $resourceIds ? Models\Media::withTrashed()->select(self::RESOURCE)->whereIn('id', $resourceIds)->get() : collect();
        $files = $resources->isNotEmpty() ? Models\StoredFile::withTrashed()->select(self::FILE)->whereIn('id', $resources->pluck('file_id'))->get() : collect();
        $result = [];
        foreach (compact('chapters', 'resources', 'files') as $kind => $rows) {
            $result[$kind] = $rows->mapWithKeys(fn ($row) => [$row->id => $row->only(self::FIELDS[$kind])])->all();
        }

        return json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    public function details(array $before, array $after): array
    {
        $changes = [];
        $workIds = [];
        foreach (self::MODELS as $kind => $model) {
            if ($kind === 'files') {
                continue;
            }
            foreach ($after[$kind] as $id => $row) {
                $previous = $before[$kind][$id] ?? null;
                $fields = array_values(array_filter(array_keys($row), fn ($field) => $field !== 'id' && ($previous === null || $previous[$field] !== $row[$field])));
                if (! $fields) {
                    continue;
                }
                $changes[$kind][$id] = ['before' => $previous, 'after' => $row, 'fields' => $fields];
                $workIds[] = $row['abs_list_id'];
                if ($previous) {
                    $workIds[] = $previous['abs_list_id'];
                }
            }
        }

        return $changes ? ['restore' => ['id' => (string) Str::uuid(), 'work_ids' => array_values(array_unique($workIds)), 'changes' => $changes]] : [];
    }

    public function preview(Models\User $user, int $history): array
    {
        $entry = Models\ActivityLog::query()->select(['id', 'action', 'details'])->findOrFail($history);
        if (EntityRestore::supports($entry)) {
            return $this->entities->preview($user, $history);
        }

        return DB::transaction(function () use ($user, $history) {
            [$log, $snapshot, $works] = $this->locked($user, $history);
            $inspection = $this->inspect($snapshot, $works);

            return [
                'id' => $log->id, 'subject' => $log->subject, 'action' => $log->action,
                'versions' => $works->mapWithKeys(fn ($work) => [$work->id => $work->revision])->all(),
                'changes' => $inspection['summary'], 'conflicts' => $inspection['conflicts'],
                'can_restore' => ! $inspection['conflicts'],
            ];
        });
    }

    public function restore(Models\User $user, int $history, array $versions): array
    {
        $entry = Models\ActivityLog::query()->select(['id', 'action', 'details'])->findOrFail($history);
        if (EntityRestore::supports($entry)) {
            return $this->entities->restore($user, $history, $versions);
        }

        return DB::transaction(function () use ($user, $history, $versions) {
            [$log, $snapshot, $works] = $this->locked($user, $history);
            foreach ($works as $work) {
                abort_unless(($versions[$work->id] ?? null) === $work->revision, 409, '预览后作品又有改动，请重新检查恢复内容。');
            }
            $inspection = $this->inspect($snapshot, $works);
            abort_if($inspection['conflicts'], 409, implode(' ', $inspection['conflicts']));

            if (($snapshot['kind'] ?? null) === 'work') {
                $works->first()->restore();
            }
            if (($snapshot['kind'] ?? null) === 'work_metadata') {
                $this->metadata->apply($snapshot, $works->first());
            }

            $chapterIds = array_keys($snapshot['changes']['chapters'] ?? []);
            Models\Chapter::withTrashed()->whereIn('id', $chapterIds)->update(['slot_active' => null, 'primary_media_id' => null]);
            foreach (self::MODELS as $kind => $model) {
                if ($kind === 'files') {
                    continue;
                }
                foreach ($snapshot['changes'][$kind] ?? [] as $id => $change) {
                    $row = $model::withTrashed()->select(self::FIELDS[$kind])->findOrFail($id);
                    $target = $inspection['targets'][$kind][$id];
                    $fields = $change['fields'];
                    if ($kind === 'chapters') {
                        $fields = array_values(array_diff($fields, ['primary_media_id', 'slot_active']));
                    }
                    $attributes = array_intersect_key($target, array_flip($fields));
                    if ($change['before'] === null) {
                        $attributes = $kind === 'chapters' ? ['deleted_at' => now(), 'slot_active' => null, 'primary_media_id' => null] : ['deleted_at' => now(), 'delete_reason' => '恢复到上传前'];
                    }
                    $row->forceFill($attributes)->save();
                }
            }
            foreach ($chapterIds as $id) {
                $target = $inspection['targets']['chapters'][$id];
                Models\Chapter::withTrashed()->whereKey($id)->update(['primary_media_id' => $target['primary_media_id'], 'slot_active' => $target['deleted_at'] === null ? true : null]);
            }

            $resourceIds = array_keys($snapshot['changes']['resources'] ?? []);
            Models\StoredFile::reconcileAudio(Models\Media::withTrashed()->whereIn('id', $resourceIds)->pluck('file_id')->all());

            $stamp = now()->toIso8601String();
            $related = Models\ActivityLog::query()->select(['id', 'details'])->where('details->restore->id', $snapshot['id'])->lockForUpdate()->get();
            foreach ($related as $record) {
                $details = $record->details;
                $details['restore']['restored_at'] = $stamp;
                $details['restore']['restored_by'] = $user->id;
                $record->update(['details' => $details]);
            }
            foreach ($works as $work) {
                $this->activity->record($user, 'activity.restored', $log->subject, $work->id, ['history_id' => $log->id, 'original_action' => $log->action, 'changes' => $inspection['summary']]);
                $this->sync->mark($work);
            }

            return ['message' => '已恢复，相关作品会重新同步。'];
        });
    }

    private function locked(Models\User $user, int $history): array
    {
        abort_unless($user->canEditOthers(), 403, '恢复记录需要编辑或管理员权限。');
        $log = Models\ActivityLog::query()->select(['id', 'work_id', 'action', 'subject', 'details'])->findOrFail($history);
        $snapshot = $log->details['restore'] ?? null;
        $workDeletion = $log->action === 'work.deleted';
        $metadataEdit = WorkMetadataRestore::supports($log);
        abort_unless($workDeletion || $metadataEdit || ($snapshot && ! empty($snapshot['changes'])), 422, '这条记录没有完整的恢复信息。');
        $workIds = $workDeletion || $metadataEdit ? [$log->work_id] : $snapshot['work_ids'];
        $fields = $metadataEdit ? Models\Work::FIELDS : ['id', 'name', 'type', 'revision', 'abs_library_id', 'deleted_at'];
        $works = ($workDeletion ? Models\Work::withTrashed() : Models\Work::query())->select($fields)->whereIn('id', $workIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        abort_unless($works->count() === count($workIds), 409, '相关作品已不存在，无法恢复。');
        $log = Models\ActivityLog::query()->select(['id', 'work_id', 'action', 'subject', 'details'])->lockForUpdate()->findOrFail($history);
        if ($metadataEdit && empty($log->details['restore'])) {
            $log->update(['details' => [...$log->details, 'restore' => $this->metadata->fromLegacy($log)]]);
        }
        if ($workDeletion) {
            $latest = Models\ActivityLog::query()->where('work_id', $log->work_id)->where('action', 'work.deleted')->latest('id')->value('id');
            abort_unless($latest === $log->id, 409, '作品已有更新的删除记录，请从最新记录恢复。');
            if (empty($log->details['restore'])) {
                abort_unless($works->first()->trashed(), 409, '作品已经恢复，无需再次恢复。');
                $log->update(['details' => [...$log->details, ...$this->deletedWorkDetails($works->first())]]);
            }
        }
        $snapshot = $log->details['restore'];
        abort_if(! empty($snapshot['restored_at']), 409, '这条记录已经恢复过了。');

        return [$log, $snapshot, $works];
    }

    private function inspect(array $snapshot, Collection $works): array
    {
        if (($snapshot['kind'] ?? null) === 'work_metadata') {
            return $this->metadata->inspect($snapshot, $works->first());
        }
        if (($snapshot['kind'] ?? null) === 'work') {
            $work = $works->first();
            $conflicts = [];
            if (! $work->trashed()) {
                $conflicts[] = '作品已经恢复，无需再次恢复。';
            } elseif ($work->revision !== $snapshot['revision'] || $work->deleted_at->toIso8601String() !== $snapshot['deleted_at']) {
                $conflicts[] = '作品在删除后又有改动，请刷新记录重新检查。';
            }
            $library = Models\Library::query()->select(['id', 'name', 'type'])->whereKey($work->abs_library_id)->lockForUpdate()->first();
            if (! $library) {
                $conflicts[] = '原分类已删除，无法恢复到原分类。';
            } elseif ($library->type !== $work->type) {
                $conflicts[] = '原分类类型与作品不一致，无法恢复。';
            }

            return ['summary' => [['kind' => 'work', 'id' => $work->id, 'name' => $work->name, 'from' => '已删除', 'to' => '恢复作品'.($library ? ' · '.$library->name : '')]], 'conflicts' => $conflicts];
        }
        $changes = $snapshot['changes'];
        $current = $this->capture(array_keys($changes['chapters'] ?? []), array_keys($changes['resources'] ?? []));
        $targets = $current;
        $conflicts = [];
        $summary = [];
        $labelIds = [];
        $primaryIds = [];
        foreach ($changes['resources'] ?? [] as $change) {
            $labelIds[] = $change['after']['abs_item_id'];
            if ($change['before']) {
                $labelIds[] = $change['before']['abs_item_id'];
            }
        }
        foreach ($changes['chapters'] ?? [] as $change) {
            $primaryIds[] = $change['after']['primary_media_id'];
            if ($change['before']) {
                $primaryIds[] = $change['before']['primary_media_id'];
            }
        }
        $labels = Models\Chapter::withTrashed()->select(['id', 'title', 'season_number', 'episode_number'])->whereIn('id', array_unique($labelIds))->get()->keyBy('id');
        $primaryNames = Models\Media::withTrashed()->whereIn('id', array_filter(array_unique($primaryIds)))->pluck('name', 'id')->all();
        foreach ($changes as $kind => $rows) {
            if ($kind === 'files') {
                continue;
            }
            foreach ($rows as $id => $change) {
                $row = $current[$kind][$id] ?? null;
                $name = $change['after']['title'] ?? $change['after']['name'] ?? '关联文件';
                if (! $row) {
                    $conflicts[] = '「'.$name.'」的记录已不存在。';

                    continue;
                }
                $fields = $change['before'] === null ? array_keys($change['after']) : $change['fields'];
                foreach ($fields as $field) {
                    if ($row[$field] !== $change['after'][$field]) {
                        $conflicts[] = '「'.$name.'」在这次操作后又有改动，请先处理后续改动。';
                        break;
                    }
                }
                $target = $row;
                if ($change['before'] === null) {
                    $target = [...$row, 'deleted_at' => 'restore', 'primary_media_id' => null, 'slot_active' => null];
                } else {
                    foreach ($change['fields'] as $field) {
                        $target[$field] = $change['before'][$field];
                    }
                }
                $targets[$kind][$id] = $target;
                if ($kind !== 'files') {
                    $summary[] = ['kind' => $kind === 'chapters' ? 'chapter' : 'resource', 'id' => $id, 'name' => $name, 'from' => $this->label($kind, $row, $labels, $primaryNames), 'to' => $this->label($kind, $target, $labels, $primaryNames)];
                }
                if ($kind === 'resources' && $row['deleted_at'] !== null && $target['deleted_at'] === null) {
                    $file = Models\StoredFile::withTrashed()->select(['id', 'file_path'])->find($row['file_id']);
                    if (! $file || ! Storage::disk('abs')->exists($file->file_path)) {
                        $conflicts[] = '「'.$name.'」的原始音频文件不存在，无法恢复。';
                    }
                }
            }
        }

        $chapters = Models\Chapter::query()->select(self::CHAPTER)->whereIn('abs_list_id', $snapshot['work_ids'])->get()->mapWithKeys(fn ($row) => [$row->id => $row->only(self::CHAPTER)])->all();
        foreach ($targets['chapters'] as $id => $target) {
            $chapters[$id] = $target;
        }
        $slots = [];
        foreach ($chapters as $id => $chapter) {
            if ($chapter['deleted_at'] !== null) {
                continue;
            }
            $slot = $chapter['abs_list_id'].':'.$chapter['season_number'].':'.$chapter['episode_number'];
            if (isset($slots[$slot])) {
                $conflicts[] = 'S'.$chapter['season_number'].'E'.$chapter['episode_number'].' 的编号已被其他章节占用。';
            }
            $slots[$slot] = $id;
        }

        $affected = array_keys($targets['chapters']);
        foreach ($targets['resources'] as $resource) {
            $affected[] = $resource['abs_item_id'];
        }
        foreach ($current['resources'] as $resource) {
            $affected[] = $resource['abs_item_id'];
        }
        $resources = Models\Media::query()->select(self::RESOURCE)->whereIn('abs_item_id', array_unique($affected))->get()->mapWithKeys(fn ($row) => [$row->id => $row->only(self::RESOURCE)])->all();
        foreach ($targets['resources'] as $id => $target) {
            $resources[$id] = $target;
        }
        $members = [];
        foreach ($resources as $resource) {
            if ($resource['deleted_at'] !== null) {
                continue;
            }
            $chapter = $chapters[$resource['abs_item_id']] ?? null;
            if (! $chapter || $chapter['deleted_at'] !== null || $chapter['abs_list_id'] !== $resource['abs_list_id']) {
                $conflicts[] = '「'.$resource['name'].'」的目标章节已删除或不属于原作品。';
            }
            $members[$resource['abs_item_id']][] = $resource['id'];
        }
        foreach (array_unique($affected) as $id) {
            $chapter = $chapters[$id] ?? null;
            $ids = $members[$id] ?? [];
            if (! $chapter) {
                continue;
            }
            if ($chapter['deleted_at'] !== null) {
                if ($ids) {
                    $conflicts[] = '「'.$chapter['title'].'」还有后续上传的资源，不能移除这个章节。';
                }
            } elseif (($ids && ! in_array($chapter['primary_media_id'], $ids, true)) || (! $ids && $chapter['primary_media_id'] !== null)) {
                $conflicts[] = '「'.$chapter['title'].'」恢复后无法保留有效的唯一主资源，请先整理当前资源。';
            }
        }

        return ['targets' => $targets, 'conflicts' => array_values(array_unique($conflicts)), 'summary' => $summary];
    }

    private function label(string $kind, array $row, $labels, array $primaryNames): string
    {
        if ($row['deleted_at'] !== null) {
            return '已删除';
        }
        if ($kind === 'chapters') {
            return 'S'.$row['season_number'].'E'.$row['episode_number'].' '.$row['title'].' · 播放序号 '.$row['index'].' · 主资源：'.($primaryNames[$row['primary_media_id']] ?? '无');
        }
        $chapter = $labels->get($row['abs_item_id']);

        return $chapter ? 'S'.$chapter->season_number.'E'.$chapter->episode_number.' '.$chapter->title : '原章节不存在';
    }
}
