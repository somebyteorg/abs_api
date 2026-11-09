<?php

namespace App\Jobs;

use App\Services;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DebounceFor;

#[DebounceFor(60)]
class SyncLibrary implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 120;

    public function __construct(public int $libraryId) {}

    public function debounceId(): string
    {
        return 'library:'.$this->libraryId;
    }

    public function backoff(): array
    {
        return [60, 180, 600];
    }

    public function handle(Services\AbsSync $sync): void
    {
        $sync->syncLibrary($this->libraryId);
    }
}
