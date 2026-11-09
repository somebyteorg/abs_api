<?php

namespace App\Services;

use App\Models;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ListeningStats
{
    public function get(Models\User $viewer, array $options): array
    {
        $now = CarbonImmutable::now();
        $period = $options['period'] ?? 'week';
        $type = $options['type'] ?? 'all';
        $sort = $options['sort'] ?? 'seconds';
        $limit = (int) ($options['limit'] ?? 10);
        $base = Models\Abs\PlaybackSession::query()->forAudience($viewer, $type);
        $from = match ($period) {
            'today' => $now->startOfDay(),
            'month' => $now->subDays(29)->startOfDay(),
            'year' => $now->subDays(364)->startOfDay(),
            'all' => CarbonImmutable::parse((clone $base)->min('date') ?: $now->toDateString())->startOfDay(),
            'custom' => CarbonImmutable::parse($options['from'])->startOfDay(),
            default => $now->subDays(6)->startOfDay(),
        };
        $to = $period === 'custom' ? CarbonImmutable::parse($options['to'])->startOfDay() : $now->startOfDay();
        abort_if($period === 'custom' && $from->diffInDays($to) > 365, 422, '自选日期最多查看 366 天。');
        $query = (clone $base)->whereBetween('date', [$from->toDateString(), $to->toDateString()]);
        $emosId = Models\User::query()->whereKey($viewer->id)->value('emos_id');
        $serverUser = Models\Abs\User::query()->select('id')->whereRaw('LOWER(emos_id) = ?', [strtolower((string) $emosId)])->first();
        $totals = (clone $query)->selectRaw("COALESCE(SUM(timelistening), 0) AS seconds, COUNT(DISTINCT userid) AS listeners, COUNT(*) AS sessions, COUNT(DISTINCT extradata->>'libraryItemId') AS works")
            ->selectRaw('COALESCE(SUM(CASE WHEN userid = ? THEN timelistening ELSE 0 END), 0) AS personal_seconds', [$serverUser?->id])->first();
        $breakdown = (clone $query)->select('mediaitemtype')->selectRaw('SUM(timelistening) AS seconds')->groupBy('mediaitemtype')->get();
        $listenerRows = (clone $query)->whereNotNull('userid')->select('userid')->selectRaw('SUM(timelistening) AS seconds, COUNT(*) AS sessions')
            ->groupBy('userid')->orderByDesc('seconds')->orderBy('userid')->limit($limit)->get();
        $people = $this->people($listenerRows->pluck('userid'));
        // Preserve the offset: query-builder DateTime bindings otherwise discard it.
        $recentQuery = (clone $base)->where('updatedat', '>=', $now->subMinutes(2)->toIso8601String())->whereNotNull('userid');

        return [
            'updated_at' => $now->toIso8601String(),
            'timezone' => config('app.timezone'),
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'monthly' => $period === 'all'],
            'summary' => [
                'seconds' => (float) $totals->seconds, 'listeners' => (int) $totals->listeners,
                'sessions' => (int) $totals->sessions, 'works' => (int) $totals->works,
                'personal_seconds' => (float) $totals->personal_seconds,
                'recent_listeners' => (clone $recentQuery)->distinct()->count('userid'),
            ],
            'trend' => $this->trend($query, $from, $to, $period === 'all', $serverUser?->id),
            'breakdown' => [
                'book' => (float) ($breakdown->firstWhere('mediaitemtype', 'book')?->seconds ?? 0),
                'podcast' => (float) ($breakdown->firstWhere('mediaitemtype', 'podcastEpisode')?->seconds ?? 0),
            ],
            'listeners' => $listenerRows->map(fn ($row) => ['key' => $row->userid, ...$people[$row->userid], 'seconds' => (float) $row->seconds, 'sessions' => (int) $row->sessions])->all(),
            'popular' => $this->popular($query, $viewer, $sort, $limit),
            'uploaders' => $this->uploaders($query, $viewer, $limit),
            'recent' => $this->recent($recentQuery, $viewer),
        ];
    }

    private function trend(Builder $query, CarbonImmutable $from, CarbonImmutable $to, bool $monthly, ?string $userId): array
    {
        $bucket = $monthly ? 'SUBSTRING(date, 1, 7)' : 'date';
        $rows = (clone $query)->selectRaw("$bucket AS bucket, SUM(timelistening) AS seconds, COUNT(DISTINCT userid) AS listeners, COUNT(*) AS sessions")
            ->selectRaw('SUM(CASE WHEN userid = ? THEN timelistening ELSE 0 END) AS personal_seconds', [$userId])->groupByRaw($bucket)->get()->keyBy('bucket');
        $result = [];
        $cursor = $monthly ? $from->startOfMonth() : $from;
        while ($cursor <= $to) {
            $key = $cursor->format($monthly ? 'Y-m' : 'Y-m-d');
            $row = $rows->get($key);
            $result[] = ['date' => $key, 'seconds' => (float) ($row?->seconds ?? 0), 'listeners' => (int) ($row?->listeners ?? 0), 'sessions' => (int) ($row?->sessions ?? 0), 'personal_seconds' => (float) ($row?->personal_seconds ?? 0)];
            $cursor = $monthly ? $cursor->addMonth() : $cursor->addDay();
        }

        return $result;
    }

    private function workTotals(Builder $query): Builder
    {
        return (clone $query)->whereNotNull('extradata->libraryItemId')
            ->selectRaw("extradata->>'libraryItemId' AS work_key, SUM(timelistening) AS seconds, COUNT(DISTINCT userid) AS listeners, COUNT(*) AS sessions, MAX(displaytitle) AS title, MAX(mediaitemtype) AS media_type")
            ->groupByRaw("extradata->>'libraryItemId'");
    }

    private function popular(Builder $query, Models\User $viewer, string $sort, int $limit): array
    {
        $rows = $this->workTotals($query)->orderByDesc($sort)->orderBy('work_key')->limit($limit)->get();
        $works = $this->works($rows->pluck('work_key'), $viewer);

        return $rows->filter(fn ($row) => $works->get($row->work_key) !== false)->map(fn ($row) => [
            'key' => $row->work_key, ...($works->get($row->work_key) ?? $this->missingWork($row->title, $row->media_type)),
            'seconds' => (float) $row->seconds, 'listeners' => (int) $row->listeners, 'sessions' => (int) $row->sessions,
        ])->values()->all();
    }

    /** Participation measures the reach of whole works, never an individual's audio listening time. */
    private function uploaders(Builder $query, Models\User $viewer, int $limit): array
    {
        $totals = [];
        foreach ($this->workTotals($query)->orderBy('work_key')->lazy(300)->chunk(300) as $batch) {
            $batch = collect($batch->all());
            $works = Models\Work::query()->visibleTo($viewer)->select(['id', 'abs_id'])->whereIn('abs_id', $batch->pluck('work_key'))->get()->keyBy('id');
            $participants = Models\Media::query()->select(['abs_list_id', 'user_id'])->whereHas('file')->whereIn('abs_list_id', $works->keys())
                ->whereIn('id', Models\Chapter::query()->select('primary_media_id')->whereIn('abs_list_id', $works->keys()))->distinct()->get();
            $seconds = $batch->keyBy('work_key');
            foreach ($participants as $participant) {
                $id = $participant->user_id;
                $totals[$id] ??= ['id' => $id, 'seconds' => 0.0, 'works' => 0];
                $totals[$id]['seconds'] += (float) $seconds[$works[$participant->abs_list_id]->abs_id]->seconds;
                $totals[$id]['works']++;
            }
        }
        $ranked = collect($totals)->sortBy([['seconds', 'desc'], ['id', 'asc']])->take($limit);
        $members = Models\User::query()->select(['id', 'username', 'pseudonym', 'avatar'])->whereIn('id', $ranked->pluck('id'))->get()->keyBy('id');

        return $ranked->filter(fn ($row) => $members->has($row['id']))->map(fn ($row) => [
            'key' => (string) $row['id'], 'name' => $members[$row['id']]->display_name, 'avatar' => $members[$row['id']]->avatar,
            'seconds' => $row['seconds'], 'works' => $row['works'],
        ])->values()->all();
    }

    private function recent(Builder $query, Models\User $viewer): array
    {
        $rows = (clone $query)->select(['id', 'userid', 'displaytitle', 'mediaitemtype', 'extradata', 'currenttime', 'duration', 'updatedat'])
            ->orderByDesc('updatedat')->limit(24)->get()->unique('userid')->take(8);
        $people = $this->people($rows->pluck('userid'));
        $works = $this->works($rows->pluck('extradata.libraryItemId')->filter(), $viewer);

        return $rows->filter(fn ($row) => $works->get($row->extradata['libraryItemId'] ?? '') !== false)->map(fn ($row) => [
            'key' => $row->id, 'listener' => $people[$row->userid],
            'work' => $works->get($row->extradata['libraryItemId'] ?? '') ?? $this->missingWork($row->displaytitle, $row->mediaitemtype),
            'progress' => $row->duration > 0 ? min(1, max(0, $row->currenttime / $row->duration)) : 0,
            'updated_at' => $row->updatedat->toIso8601String(),
        ])->values()->all();
    }

    private function people(Collection $ids): array
    {
        $users = Models\Abs\User::query()->select(['id', 'emos_id', 'username'])->whereIn('id', $ids)->get()->keyBy('id');
        $emosIds = $users->pluck('emos_id')->filter()->map(fn ($id) => strtolower($id))->unique();
        $members = Models\User::query()->select(['emos_id', 'username', 'pseudonym', 'avatar'])
            ->whereIn(Models\User::query()->raw('LOWER(emos_id)'), $emosIds)->get()->keyBy(fn ($member) => strtolower($member->emos_id));
        $people = [];
        foreach ($ids as $id) {
            $user = $users->get($id);
            $member = $members->get(strtolower((string) $user?->emos_id));
            $people[$id] = ['name' => $member?->display_name ?? $user?->username ?? '已注销听众', 'avatar' => $member?->avatar];
        }

        return $people;
    }

    private function works(Collection $ids, Models\User $viewer): Collection
    {
        $works = Models\Work::withTrashed()->select(['id', 'abs_id', 'name', 'type', 'cover_file_id', 'date_air', 'is_adult', 'deleted_at'])->whereIn('abs_id', $ids)->get()
            ->mapWithKeys(fn ($work) => [$work->abs_id => ! $viewer->adult_mode && $work->is_adult ? false : [
                'id' => $work->trashed() ? null : $work->id, 'name' => $work->name, 'type' => $work->type,
                'cover_url' => $work->cover_url, 'year' => $work->date_air?->format('Y'), 'unavailable' => $work->trashed(),
            ]]);
        $unmapped = Models\Abs\LibraryItem::query()->select(['id', 'title', 'mediatype'])->whereIn('id', $ids->diff($works->keys()))->get();
        foreach ($unmapped as $work) {
            $works->put($work->id, $this->missingWork($work->title, $work->mediatype));
        }

        return $works;
    }

    private function missingWork(?string $title, string $type): array
    {
        return ['id' => null, 'name' => $title ?: '已下架作品', 'type' => $type === 'book' ? 'book' : 'podcast', 'cover_url' => null, 'year' => null, 'unavailable' => true];
    }
}
