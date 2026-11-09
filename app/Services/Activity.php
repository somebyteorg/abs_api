<?php

namespace App\Services;

use App\Models;

class Activity
{
    public function record(Models\User $actor, string $action, string $subject, ?int $workId = null, array $details = []): void
    {
        Models\ActivityLog::create(['user_id' => $actor->id, 'action' => $action, 'subject' => $subject, 'work_id' => $workId, 'details' => $details, 'created_at' => now()]);
    }
}
