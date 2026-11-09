<?php

use App\Jobs\SyncLibrary;
use App\Jobs\SyncWork;
use App\Models;
use App\Services;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Schedule::command('horizon:snapshot')->everyFiveMinutes();

Schedule::call(function () {
    Models\Library::withTrashed()->select(['id'])->where('need_sync', true)->where('updated_at', '<=', now()->subMinutes(5))
        ->where(fn ($q) => $q->whereNull('sync_enqueued_at')->orWhere('sync_enqueued_at', '<=', now()->subMinutes(5)))
        ->chunkById(100, function ($libraries) {
            foreach ($libraries as $library) {
                SyncLibrary::dispatch($library->id);
                $library->timestamps = false;
                $library->update(['sync_enqueued_at' => now()]);
            }
        });
    Models\Work::withTrashed()->select(['id'])->where('need_sync', true)->where('updated_at', '<=', now()->subMinutes(5))
        ->where(fn ($q) => $q->whereNull('sync_enqueued_at')->orWhere('sync_enqueued_at', '<=', now()->subMinutes(5)))
        ->chunkById(100, function ($works) {
            foreach ($works as $work) {
                SyncWork::dispatch($work->id);
                $work->timestamps = false;
                $work->update(['sync_enqueued_at' => now()]);
            }
        });
})->name('abs-sync-outbox')->everyFiveMinutes()->withoutOverlapping();

Schedule::call(function () {
    Models\UploadSession::query()->select(['id'])->where('status', 'uploading')->where('expires_at', '<', now())
        ->chunkById(100, function ($uploads) {
            foreach ($uploads as $upload) {
                $lock = Cache::lock('upload:'.$upload->id, 180);
                if (! $lock->get()) {
                    continue;
                }
                try {
                    Models\UploadSession::query()->whereKey($upload->id)->where('status', 'uploading')->update(['status' => 'expired']);
                    app(Services\UploadChunks::class)->discard($upload->id);
                } finally {
                    $lock->release();
                }
            }
        });
})->name('expire-upload-chunks')->hourly()->withoutOverlapping();
