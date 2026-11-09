<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireRole
{
    public function handle(Request $request, Closure $next, string $level = 'user')
    {
        $user = $request->user();
        $allowed = match ($level) {
            'admin' => $user?->role === 'admin',
            'edit' => $user?->canEditOthers(),
            default => $user?->canContribute(),
        };
        abort_unless($allowed, 403, '当前账号没有这个操作的权限。');

        return $next($request);
    }
}
