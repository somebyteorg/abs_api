<?php

namespace App\Services;

use App\Models;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class WorkMetadataRestore
{
    private const FIELDS = ['name' => '作品名称', 'tagline' => '副标题', 'description' => '简介', 'abs_library_id' => '分类', 'date_air' => '首播日期', 'is_adult' => '成人内容', 'cover_file_id' => '封面'];

    private function fields(array $values): array
    {
        $values = array_intersect_key($values, self::FIELDS);
        if (array_key_exists('date_air', $values)) {
            $values['date_air'] = filled($values['date_air']) ? Carbon::parse($values['date_air'])->format('Y-m-d') : null;
        }
        if (array_key_exists('abs_library_id', $values)) {
            $values['abs_library_id'] = (int) $values['abs_library_id'];
        }
        if (array_key_exists('is_adult', $values)) {
            $values['is_adult'] = (bool) $values['is_adult'];
        }

        return $values;
    }

    private function authors(Models\Work $work): array
    {
        return Models\WorkAuthor::query()->select(['author_id', 'abs_id', 'created_at', 'updated_at'])->where('work_id', $work->id)->orderBy('author_id')->get()
            ->map(fn ($link) => ['author_id' => $link->author_id, 'abs_id' => $link->abs_id, 'created_at' => $link->created_at->toIso8601String(), 'updated_at' => $link->updated_at->toIso8601String()])->all();
    }

    public function capture(Models\Work $work, bool $withAuthors): array
    {
        return ['fields' => $this->fields($work->only(array_keys(self::FIELDS))), 'authors' => $withAuthors ? $this->authors($work) : null];
    }

    private function snapshot(int $workId, array $before, array $after, ?array $authors = null): array
    {
        return ['id' => (string) Str::uuid(), 'kind' => 'work_metadata', 'work_ids' => [$workId], 'before' => $before, 'after' => $after, 'authors' => $authors];
    }

    public function details(Models\Work $work, array $before, array $after): array
    {
        $changed = array_filter($after['fields'], fn ($value, $key) => $value !== $before['fields'][$key], ARRAY_FILTER_USE_BOTH);
        $old = array_intersect_key($before['fields'], $changed);
        $authors = $after['authors'] !== null ? ['before' => $before['authors'], 'after' => $after['authors']] : null;
        $details = ['before' => $old, 'after' => $changed, 'authors_changed' => $authors !== null];
        if (array_key_exists('abs_library_id', $changed)) {
            $names = Models\Library::withTrashed()->whereIn('id', [$old['abs_library_id'], $changed['abs_library_id']])->pluck('name', 'id');
            $details['library_change'] = ['from' => $names->get($old['abs_library_id']), 'to' => $names->get($changed['abs_library_id'])];
        }
        if ($authors !== null) {
            $ids = array_column([...$authors['before'], ...$authors['after']], 'author_id');
            $names = Models\Author::withTrashed()->whereIn('id', $ids)->pluck('name', 'id');
            $details['authors_change'] = array_map(fn ($links) => array_map(fn ($link) => $names->get($link['author_id'], '已删除作者'), $links), $authors);
        }
        if ($changed || $authors !== null) {
            $details['restore'] = $this->snapshot($work->id, $old, $changed, $authors);
        }

        return $details;
    }

    public static function supports(Models\ActivityLog $log): bool
    {
        if ($log->action === 'cover.uploaded') {
            return $log->work_id && isset($log->details['file_id']) && array_key_exists('previous_file_id', $log->details);
        }
        if ($log->action !== 'work.updated') {
            return false;
        }
        if (($log->details['restore']['kind'] ?? null) === 'work_metadata') {
            return true;
        }
        if (! empty($log->details['authors_changed'])) {
            return false;
        }
        $before = $log->details['before'] ?? null;
        $after = $log->details['after'] ?? null;

        return is_array($before) && is_array($after) && $after && ! array_diff_key($after, self::FIELDS) && ! array_diff_key($before, $after) && ! array_diff_key($after, $before);
    }

    public function fromLegacy(Models\ActivityLog $log): array
    {
        if ($log->action === 'cover.uploaded') {
            return $this->snapshot($log->work_id, ['cover_file_id' => $log->details['previous_file_id']], ['cover_file_id' => $log->details['file_id']]);
        }

        return $this->snapshot($log->work_id, $this->fields($log->details['before']), $this->fields($log->details['after']));
    }

    private function membership(array $links): array
    {
        return array_column($links, 'abs_id', 'author_id');
    }

    private function display(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '未填写';
        }
        if (is_bool($value)) {
            return $value ? '是' : '否';
        }

        return (string) $value;
    }

    public function inspect(array $snapshot, Models\Work $work): array
    {
        $current = $this->fields($work->only(array_keys(self::FIELDS)));
        $conflicts = [];
        $summary = [];
        $libraryNames = array_key_exists('abs_library_id', $snapshot['after'])
            ? Models\Library::withTrashed()->whereIn('id', [$snapshot['before']['abs_library_id'], $snapshot['after']['abs_library_id']])->pluck('name', 'id') : collect();
        foreach ($snapshot['after'] as $field => $value) {
            if ($current[$field] !== $value) {
                $conflicts[] = '「'.self::FIELDS[$field].'」在这次操作后又有改动，请先处理后续改动。';
            }
            $from = $field === 'abs_library_id' ? $libraryNames->get($value, '已删除分类') : ($field === 'cover_file_id' ? ($value ? '当前封面' : '无封面') : $value);
            $to = $field === 'abs_library_id' ? $libraryNames->get($snapshot['before'][$field], '已删除分类') : ($field === 'cover_file_id' ? ($snapshot['before'][$field] ? '原封面' : '无封面') : $snapshot['before'][$field]);
            $summary[] = ['kind' => 'work', 'id' => $work->id, 'name' => self::FIELDS[$field], 'from' => $this->display($from), 'to' => $this->display($to)];
        }
        if (! empty($snapshot['before']['cover_file_id']) && ! Models\StoredFile::withTrashed()->whereKey($snapshot['before']['cover_file_id'])->where('file_type', 'cover')->exists()) {
            $conflicts[] = '原封面记录不存在，无法恢复。';
        }
        if (array_key_exists('abs_library_id', $snapshot['before'])) {
            $library = Models\Library::query()->select(['id', 'type'])->lockForUpdate()->find($snapshot['before']['abs_library_id']);
            if (! $library) {
                $conflicts[] = '原分类已删除，无法恢复到原分类。';
            } elseif ($library->type !== $work->type) {
                $conflicts[] = '原分类类型与作品不一致，无法恢复。';
            }
        }
        if ($snapshot['authors'] !== null) {
            $authors = $snapshot['authors'];
            if ($this->membership($this->authors($work)) !== $this->membership($authors['after'])) {
                $conflicts[] = '作品的作者关联在这次操作后又有改动，请先处理后续改动。';
            }
            $beforeIds = array_column($authors['before'], 'author_id');
            $active = Models\Author::query()->select(['id'])->whereIn('id', $beforeIds)->orderBy('id')->lockForUpdate()->get();
            if ($active->count() !== count($beforeIds)) {
                $conflicts[] = '原来关联的部分作者已删除，无法恢复作者关联。';
            }
            $names = Models\Author::withTrashed()->whereIn('id', [...$beforeIds, ...array_column($authors['after'], 'author_id')])->pluck('name', 'id');
            $label = fn ($links) => implode('、', array_map(fn ($link) => $names->get($link['author_id'], '已删除作者'), $links)) ?: '未关联作者';
            $summary[] = ['kind' => 'work', 'id' => $work->id, 'name' => '关联作者', 'from' => $label($authors['after']), 'to' => $label($authors['before'])];
        }

        return ['summary' => $summary, 'conflicts' => $conflicts];
    }

    public function apply(array $snapshot, Models\Work $work): void
    {
        $work->fill($snapshot['before'])->save();
        if (! empty($snapshot['before']['cover_file_id'])) {
            Models\StoredFile::withTrashed()->whereKey($snapshot['before']['cover_file_id'])->update(['deleted_at' => null]);
        }
        if ($snapshot['authors'] !== null) {
            $links = [];
            foreach ($snapshot['authors']['before'] as $link) {
                $links[$link['author_id']] = array_diff_key($link, ['author_id' => true]);
            }
            $work->authors()->sync($links);
        }
    }
}
