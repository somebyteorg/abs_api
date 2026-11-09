<?php

namespace App\Services;

use App\Models;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class ActivityHistory
{
    public function page(Models\User $user, array $data): LengthAwarePaginator
    {
        $page = Models\ActivityLog::query()->select(['id', 'user_id', 'work_id', 'action', 'subject', 'details', 'created_at'])
            ->with('actor:id,username,pseudonym')
            ->when($data['user'] ?? null, fn ($q, $term) => $q->whereHas('actor', fn ($users) => $users->named($term)))
            ->when($data['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v.' 00:00:00'))
            ->when($data['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<', Carbon::parse($v)->addDay()))
            ->when($data['work_id'] ?? null, fn ($q, $v) => $q->where('work_id', $v))
            ->orderByDesc('id')->paginate(30);
        $personIds = $page->getCollection()->flatMap(fn ($log) => [$log->details['uploader_id'] ?? null, $log->details['restore']['restored_by'] ?? null])->filter()->unique();
        $people = Models\User::query()->select(['id', 'username', 'pseudonym'])->whereIn('id', $personIds)->get()->keyBy('id');
        $workIds = $page->getCollection()->flatMap(fn ($log) => [$log->work_id, ...($log->details['restore']['work_ids'] ?? [])])->filter()->unique();
        $availableWorks = Models\Work::query()->whereIn('id', $workIds)->pluck('id')->all();
        $deletedWorks = Models\Work::onlyTrashed()->whereIn('id', $workIds)->pluck('id')->all();
        $authorIds = $page->getCollection()->map(fn ($log) => $log->details['author_id'] ?? null)->filter()->unique();
        $availableAuthors = Models\Author::query()->whereIn('id', $authorIds)->pluck('id')->all();
        $latestDeletions = Models\ActivityLog::query()->whereIn('work_id', $deletedWorks)->where('action', 'work.deleted')->select('work_id')->selectRaw('MAX(id) AS id')->groupBy('work_id')->pluck('id', 'work_id');
        $page->through(function ($log) use ($user, $people, $availableWorks, $deletedWorks, $latestDeletions, $availableAuthors) {
            $details = $log->details;
            $snapshot = $details['restore'] ?? null;
            $canRestore = $user->canEditOthers() && empty($snapshot['restored_at'])
                && $this->restorable($log, $availableWorks, $deletedWorks, $latestDeletions->all());
            $log->setAttribute('can_restore', $canRestore);
            $log->setAttribute('restored_at', $snapshot['restored_at'] ?? null);
            $log->setAttribute('restored_by', $people->get($snapshot['restored_by'] ?? null));
            $log->setAttribute('uploader', $people->get($details['uploader_id'] ?? null));
            $log->setAttribute('work_available', in_array($log->work_id, $availableWorks, true));
            $log->setAttribute('author_available', in_array($details['author_id'] ?? null, $availableAuthors, true));
            unset($details['restore']);
            $log->setAttribute('details', $details);

            return $log;
        });

        return $page;
    }

    private function restorable(Models\ActivityLog $log, array $availableWorks, array $deletedWorks, array $latestDeletions): bool
    {
        if ($log->action === 'work.deleted') {
            return in_array($log->work_id, $deletedWorks, true)
                && ($latestDeletions[$log->work_id] ?? null) === $log->id;
        }
        if (EntityRestore::supports($log)) {
            return true;
        }
        if (WorkMetadataRestore::supports($log)) {
            return in_array($log->work_id, $availableWorks, true);
        }
        $snapshot = $log->details['restore'] ?? null;

        return $snapshot && ! array_diff($snapshot['work_ids'], $availableWorks);
    }
}
