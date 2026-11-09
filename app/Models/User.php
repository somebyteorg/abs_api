<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Builder;

class User extends Base implements AuthenticatableContract
{
    use Authenticatable;

    public const PUBLIC_FIELDS = ['id', 'username', 'avatar', 'pseudonym', 'role', 'size_upload', 'notice_version', 'notice_accepted_at'];

    public const SESSION_FIELDS = [...self::PUBLIC_FIELDS, 'adult_mode'];

    public const AUTH_FIELDS = [...self::SESSION_FIELDS, 'remember_token'];

    protected $hidden = ['emos_id', 'remember_token'];

    protected $appends = ['display_name'];

    protected function casts(): array
    {
        return ['size_upload' => 'integer', 'notice_accepted_at' => 'datetime', 'adult_mode' => 'boolean'];
    }

    public function scopeNamed(Builder $query, string $term): void
    {
        $query->where(fn ($names) => $names->whereLike('username', '%'.$term.'%')->orWhereLike('pseudonym', '%'.$term.'%'));
    }

    public function getDisplayNameAttribute(): string
    {
        return filled($this->pseudonym) ? $this->pseudonym : $this->username;
    }

    // OAuth accounts have no local password; Laravel's recaller requires a string.
    public function getAuthPassword(): string
    {
        return '';
    }

    public function canContribute(): bool
    {
        return in_array($this->role, ['user', 'edit', 'admin'], true);
    }

    public function canEditOthers(): bool
    {
        return in_array($this->role, ['edit', 'admin'], true);
    }
}
