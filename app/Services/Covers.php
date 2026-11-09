<?php

namespace App\Services;

use App\Models;

class Covers
{
    public function validate(?string $fileId, Models\User $user, ?string $current = null): void
    {
        if (! $fileId || $fileId === $current) {
            return;
        }
        abort_unless(Models\StoredFile::query()->whereKey($fileId)->where('file_type', 'cover')->where('user_id', $user->id)->exists(), 422, '请选择自己已上传完成的封面。');
    }

    public function attach(?string $fileId, ?int $workId = null, ?int $authorId = null): void
    {
        if (! $fileId) {
            return;
        }
        Models\StoredFile::query()->whereKey($fileId)->whereNull('abs_list_id')->whereNull('author_id')->update(['abs_list_id' => $workId, 'author_id' => $authorId]);
    }
}
