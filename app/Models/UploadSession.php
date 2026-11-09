<?php

namespace App\Models;

class UploadSession extends Base
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'file_size' => 'integer', 'chunk_size' => 'integer', 'chunk_count' => 'integer', 'allow_alternative' => 'boolean'];
    }

    public const FIELDS = ['id', 'user_id', 'abs_list_id', 'kind', 'file_name', 'file_size', 'sha256', 'chunk_size', 'chunk_count', 'season_number', 'episode_number', 'title', 'status', 'file_id', 'expires_at', 'created_at', 'chapter_id', 'allow_alternative', 'primary_choice', 'author_id'];
}
