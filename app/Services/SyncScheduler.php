<?php

namespace App\Services;

use App\Jobs\SyncLibrary;
use App\Jobs\SyncWork;
use App\Models;
use Illuminate\Support\Facades\DB;

class SyncScheduler
{
    public function mark(Models\Work $work): void
    {
        $work->forceFill(['revision' => ((int) $work->revision) + 1, 'need_sync' => true, 'sync_status' => 'pending', 'sync_error' => null, 'sync_enqueued_at' => now()])->save();
        DB::afterCommit(function () use ($work) {
            try {
                SyncWork::dispatch($work->id);
            } catch (\Throwable $exception) {
                report($exception);
            }
        });
    }

    public function markLibrary(Models\Library $library): void
    {
        $library->forceFill(['need_sync' => true, 'sync_enqueued_at' => now()])->save();
        DB::afterCommit(function () use ($library) {
            try {
                SyncLibrary::dispatch($library->id);
            } catch (\Throwable $exception) {
                report($exception);
            }
        });
    }
}
