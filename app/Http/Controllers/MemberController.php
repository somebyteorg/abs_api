<?php

namespace App\Http\Controllers;

use App\Models;
use App\Services;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MemberController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $page = Models\User::query()->select(Models\User::PUBLIC_FIELDS)->when($data['q'] ?? null, fn ($q, $v) => $q->named($v))->orderBy('id')->paginate(30);

        return $this->FormatPage($page, $page->items());
    }

    public function updateRole(Request $request, int $user, Services\Activity $activity)
    {
        $data = $request->validate(['role' => ['present', 'nullable', Rule::in(['user', 'edit', 'admin'])]]);
        abort_if($user === $request->user()->id, 422, '请让另一位管理员调整你的角色。');

        return DB::transaction(function () use ($request, $user, $activity, $data) {
            $target = Models\User::query()->select(Models\User::PUBLIC_FIELDS)->lockForUpdate()->findOrFail($user);
            $before = $target->role;
            $target->update(['role' => $data['role']]);
            $activity->record($request->user(), 'role.updated', $target->display_name, details: ['user_id' => $user, 'before' => $before, 'after' => $data['role']]);

            return ['data' => $target];
        });
    }
}
