<?php

namespace App\Services;

use App\Models;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Authors
{
    public function __construct(private SyncScheduler $sync) {}

    public function assign(Models\Work $work, array $ids): bool
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        $authors = Models\Author::query()->select(['id'])->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        abort_unless($authors->count() === count($ids), 422, '部分作者已不存在，请重新选择。');
        $current = Models\WorkAuthor::query()->select(['author_id', 'abs_id'])->where('work_id', $work->id)->get()->keyBy('author_id');
        $before = $current->keys()->all();
        sort($before);
        if ($before === $ids) {
            return false;
        }
        $links = [];
        foreach ($ids as $id) {
            $links[$id] = ['abs_id' => $current->get($id)?->abs_id ?? (string) Str::uuid()];
        }
        $work->authors()->sync($links);

        return true;
    }

    public function changed(Models\Author $author): void
    {
        // Dispatch after the author lock is released; work mutations consistently lock the work first.
        DB::afterCommit(function () use ($author) {
            Models\Work::query()->select(['id'])->whereHas('authors', fn ($q) => $q->where('abs_authors.id', $author->id))->chunkById(100, function ($works) {
                foreach ($works as $item) {
                    DB::transaction(function () use ($item) {
                        $work = Models\Work::query()->select(Models\Work::FIELDS)->lockForUpdate()->findOrFail($item->id);
                        $this->sync->mark($work);
                    });
                }
            });
        });
    }
}
