<?php

namespace App\Jobs;

use App\Models;
use App\Services;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DebounceFor;
use Throwable;

#[DebounceFor(60)]
class SyncWork implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 120;

    public function __construct(public int $workId) {}

    public function debounceId(): string
    {
        return 'work:'.$this->workId;
    }

    public function backoff(): array
    {
        return [60, 180, 600];
    }

    public function handle(Services\AbsSync $sync): void
    {
        try {
            $sync->sync($this->workId);
        } catch (Throwable $exception) {
            Models\Work::withoutTimestamps(fn () => Models\Work::withTrashed()->whereKey($this->workId)->where('need_sync', true)->update(['sync_status' => 'error', 'sync_error' => '收听端同步暂未完成，请稍后重试。']));
            throw $exception;
        }
    }
}
