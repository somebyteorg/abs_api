<?php

namespace App\Http\Controllers;

use App\Models;
use App\Services;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function session(Request $request)
    {
        return ['user' => $request->user(), 'csrf_token' => csrf_token(), 'contribution' => config('contribution')];
    }

    public function sign(Request $request)
    {
        $request->session()->put('oauth_started_at', time());

        return redirect()->away(config('services.emos.url_link'), 302);
    }

    public function login(Request $request)
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:4096']]);
        $started = $request->session()->get('oauth_started_at');
        abort_unless($started && time() - $started <= 600, 419, '登录入口已过期，请从书架重新发起登录。');
        $url = rtrim((string) config('services.emos.url'), '/');
        abort_if($url === '', 503, 'EMOS 登录地址还没有配置。');
        try {
            $response = Http::timeout(15)->connectTimeout(5)->acceptJson()->withToken($data['token'])->get($url.'/api/user/base');
        } catch (ConnectionException $exception) {
            abort(502, '暂时连不上 EMOS，请稍后再试。');
        }
        abort_if($response->status() === 401, 401, '登录凭证过期了，再登录一次吧。');
        abort_unless($response->successful(), 502, '暂时连不上 EMOS，请稍后再试。');
        $profile = validator($response->json() ?? [], [
            'user_id' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255'],
            'avatar' => ['nullable', 'url:http,https', 'max:2048'],
        ])->validate();
        $user = DB::transaction(function () use ($profile) {
            $user = Models\User::query()->select([...Models\User::AUTH_FIELDS, 'emos_id'])->firstOrCreate(
                ['emos_id' => $profile['user_id']], ['username' => $profile['username'], 'avatar' => $profile['avatar'] ?? null],
            );
            $user->update(['username' => $profile['username'], 'avatar' => $profile['avatar'] ?? null]);

            return $user;
        });
        Auth::login($user, remember: true);
        $request->session()->forget('oauth_started_at');
        $request->session()->regenerate();

        return redirect('/');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => '下次再来添几本好书。']);
    }

    public function acceptNotice(Request $request, Services\Activity $activity)
    {
        $data = $request->validate(['accepted' => ['required', 'accepted'], 'version' => ['required', Rule::in([config('contribution.notice.version')])]]);

        return DB::transaction(function () use ($request, $activity, $data) {
            $user = Models\User::query()->select(Models\User::SESSION_FIELDS)->lockForUpdate()->findOrFail($request->user()->id);
            $user->notice_version = $data['version'];
            $user->notice_accepted_at = now();
            if ($user->role === null) {
                $user->role = 'user';
            }
            $user->save();
            $activity->record($user, 'notice.accepted', '同意上传须知', details: ['version' => $data['version']]);

            return ['user' => $user];
        });
    }

    public function profile(Request $request, Services\Activity $activity)
    {
        $data = $request->validate([
            'pseudonym' => ['sometimes', 'nullable', 'string', 'max:30', Rule::unique('users', 'pseudonym')->ignore($request->user()->id)],
            'adult_mode' => ['sometimes', 'required', 'boolean'],
        ]);

        return DB::transaction(function () use ($request, $activity, $data) {
            $user = Models\User::query()->select(Models\User::SESSION_FIELDS)->lockForUpdate()->findOrFail($request->user()->id);
            $before = $user->pseudonym;
            $user->fill($data);
            if ($user->isDirty('pseudonym')) {
                $activity->record($user, 'profile.updated', '修改化名', details: ['before' => $before, 'after' => $user->pseudonym]);
            }
            $user->save();

            return ['user' => $user];
        });
    }
}
