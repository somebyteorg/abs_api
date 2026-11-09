<?php

namespace App\Http\Controllers;

use App\Models;
use App\Services;
use Illuminate\Http\Request;

class ContributionController extends Controller
{
    public function uploads(Request $request, Services\ContributionUploads $uploads)
    {
        $data = $request->validate(['mine' => ['nullable', 'boolean'], 'page' => ['nullable', 'integer', 'min:1']]);
        $page = $uploads->page($request->user(), (bool) ($data['mine'] ?? false));

        return $this->FormatPage($page, $page->items());
    }

    public function stats(Request $request)
    {
        $user = $request->user();

        return [
            'total_bytes' => (int) Models\User::query()->sum('size_upload'),
            'total_works' => Models\Work::query()->count(),
            'total_uploads' => Models\Media::withTrashed()->count(),
            'contributors' => Models\User::query()->where('size_upload', '>', 0)->count(),
            'personal' => [
                'total_bytes' => (int) $user->size_upload,
                'total_works' => Models\Work::query()->where('user_id', $user->id)->count(),
                'total_uploads' => Models\Media::withTrashed()->where('user_id', $user->id)->count(),
            ],
            'leaders' => Models\User::query()->select(['id', 'username', 'pseudonym', 'size_upload'])->where('size_upload', '>', 0)->orderByDesc('size_upload')->limit(10)->get(),
        ];
    }
}
