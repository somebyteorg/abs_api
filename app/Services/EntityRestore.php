<?php

namespace App\Services;

use App\Models;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EntityRestore
{
    private const LABELS = ['name' => '名称', 'description' => '介绍', 'cover_file_id' => '封面', 'deleted_at' => '状态'];

    public function __construct(private Activity $activity, private Authors $authors, private SyncScheduler $sync) {}

    public static function supports(Models\ActivityLog $log): bool
    {
        if (($log->details['restore']['kind'] ?? null) === 'entity') {
            return true;
        }

        return match ($log->action) {
            'author.updated' => is_array($log->details['before'] ?? null) && is_array($log->details['after'] ?? null) && ! array_diff_key($log->details['after'], $log->details['before']),
            'author.cover_uploaded' => isset($log->details['author_id'], $log->details['file_id']) && array_key_exists('previous_file_id', $log->details),
            'library.updated' => is_string($log->details['before'] ?? null) && is_string($log->details['after'] ?? null),
            'library.deleted' => isset($log->details['library_id']),
            default => false,
        };
    }

    public function details(string $entity, int $id, array $before, array $after): array
    {
        $after = array_filter($after, fn ($value, $key) => array_key_exists($key, $before) && $before[$key] !== $value, ARRAY_FILTER_USE_BOTH);
        if (! $after) {
            return [];
        }

        return ['restore' => ['id' => (string) Str::uuid(), 'kind' => 'entity', 'entity' => $entity, 'entity_id' => $id, 'work_ids' => [], 'before' => array_intersect_key($before, $after), 'after' => $after]];
    }

    private function locked(Models\User $user, int $id): array
    {
        abort_unless($user->canEditOthers(), 403, '恢复记录需要编辑或管理员权限。');
        $log = Models\ActivityLog::query()->select(['id', 'action', 'subject', 'details'])->lockForUpdate()->findOrFail($id);
        abort_unless(self::supports($log), 422, '这条记录没有完整的恢复信息。');
        $entity = str_starts_with($log->action, 'author.') ? 'author' : 'library';
        $snapshot = $log->details['restore'] ?? null;
        $entityId = $snapshot['entity_id'] ?? $log->details[$entity.'_id'];
        $row = ($entity === 'author' ? Models\Author::withTrashed()->select([...Models\Author::FIELDS, 'deleted_at']) : Models\Library::withTrashed()->select([...Models\Library::FIELDS, 'updated_at']))->lockForUpdate()->findOrFail($entityId);
        if (! $snapshot) {
            $before = $log->details['before'] ?? null;
            $after = $log->details['after'] ?? null;
            if ($log->action === 'author.cover_uploaded') {
                $before = ['cover_file_id' => $log->details['previous_file_id']];
                $after = ['cover_file_id' => $log->details['file_id']];
            }
            if ($log->action === 'library.updated') {
                $before = ['name' => $before];
                $after = ['name' => $after];
            }
            if ($log->action === 'library.deleted') {
                $latest = Models\ActivityLog::query()->where('action', 'library.deleted')->where('details->library_id', $entityId)->latest('id')->value('id');
                abort_unless($latest === $log->id && $row->trashed(), 409, '分类已恢复或有新的删除记录，请检查最新记录。');
                $before = ['deleted_at' => null];
                $after = ['deleted_at' => $row->deleted_at->toJSON()];
            }
            $snapshot = $this->details($entity, $entityId, $before, $after)['restore'] ?? null;
            abort_unless($snapshot, 422, '这条记录没有实际修改。');
            $log->update(['details' => [...$log->details, 'restore' => $snapshot]]);
        }
        abort_if(! empty($snapshot['restored_at']), 409, '这条记录已经恢复过了。');
        if ($log->action === 'author.deleted') {
            $latest = Models\ActivityLog::query()->where('action', 'author.deleted')->where('details->author_id', $entityId)->latest('id')->value('id');
            abort_unless($latest === $log->id && $row->trashed(), 409, '作者已恢复或有新的删除记录，请检查最新记录。');
        }

        return [$log, $snapshot, $row];
    }

    private function inspect(array $snapshot, $row): array
    {
        $conflicts = [];
        $changes = [];
        if ($row->trashed() && ! array_key_exists('deleted_at', $snapshot['after'])) {
            $conflicts[] = '相关资料已删除，不能恢复资料修改。';
        }
        foreach ($snapshot['after'] as $field => $value) {
            if (! isset(self::LABELS[$field])) {
                $conflicts[] = '记录中存在不支持恢复的字段。';

                continue;
            }
            $current = $field === 'deleted_at' ? $row->deleted_at?->toJSON() : $row->{$field};
            if ($current !== $value) {
                $conflicts[] = '「'.self::LABELS[$field].'」在这次操作后又有改动，请先处理后续修改。';
            }
            $label = fn ($v) => $field === 'cover_file_id' ? ($v ? '已设置封面' : '无封面') : ($field === 'deleted_at' ? ($v ? '已删除' : ($snapshot['entity'] === 'author' ? '恢复作者' : '恢复分类')) : ($v ?: '未填写'));
            $changes[] = ['kind' => $snapshot['entity'], 'id' => $row->id, 'name' => self::LABELS[$field], 'from' => $label($value), 'to' => $label($snapshot['before'][$field])];
        }
        $coverId = $this->coverBefore($snapshot, $row);
        if ($coverId && ! Models\StoredFile::withTrashed()->whereKey($coverId)->where('file_type', 'cover')->exists()) {
            $conflicts[] = '原封面记录不存在，无法恢复。';
        }

        return ['changes' => $changes, 'conflicts' => $conflicts];
    }

    private function coverBefore(array $snapshot, $row): ?string
    {
        if (array_key_exists('cover_file_id', $snapshot['before'])) {
            return $snapshot['before']['cover_file_id'];
        }

        return $snapshot['entity'] === 'author' && array_key_exists('deleted_at', $snapshot['before']) && $snapshot['before']['deleted_at'] === null ? $row->cover_file_id : null;
    }

    private function version($row): int
    {
        return (int) sprintf('%u', crc32(json_encode($row->getAttributes(), JSON_THROW_ON_ERROR)));
    }

    public function preview(Models\User $user, int $id): array
    {
        return DB::transaction(function () use ($user, $id) {
            [$log, $snapshot, $row] = $this->locked($user, $id);
            $inspection = $this->inspect($snapshot, $row);

            return ['id' => $id, 'subject' => $log->subject, 'action' => $log->action, 'versions' => [$snapshot['entity'].':'.$row->id => $this->version($row)], ...$inspection, 'can_restore' => ! $inspection['conflicts']];
        });
    }

    public function restore(Models\User $user, int $id, array $versions): array
    {
        return DB::transaction(function () use ($user, $id, $versions) {
            [$log, $snapshot, $row] = $this->locked($user, $id);
            abort_unless(($versions[$snapshot['entity'].':'.$row->id] ?? null) === $this->version($row), 409, '预览后资料又有变化，请重新检查。');
            $inspection = $this->inspect($snapshot, $row);
            abort_if($inspection['conflicts'], 409, implode(' ', $inspection['conflicts']));
            $row->forceFill($snapshot['before'])->save();
            $coverId = $this->coverBefore($snapshot, $row);
            if ($coverId) {
                Models\StoredFile::withTrashed()->whereKey($coverId)->update(['deleted_at' => null]);
            }
            $snapshot['restored_at'] = now()->toIso8601String();
            $snapshot['restored_by'] = $user->id;
            $log->update(['details' => [...$log->details, 'restore' => $snapshot]]);
            $this->activity->record($user, 'activity.restored', $log->subject, details: [$snapshot['entity'].'_id' => $row->id, 'history_id' => $id, 'original_action' => $log->action, 'changes' => $inspection['changes']]);
            if ($snapshot['entity'] === 'author') {
                $this->authors->changed($row);
            } else {
                $this->sync->markLibrary($row);
            }

            return ['message' => '已恢复，相关内容会重新同步。'];
        });
    }
}
