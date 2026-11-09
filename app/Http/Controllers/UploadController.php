<?php

namespace App\Http\Controllers;

use App\Services;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UploadController extends Controller
{
    public function duplicates(Request $request, Services\AudioResources $resources)
    {
        $data = $request->validate(['sha256' => ['required', 'regex:/^[a-f0-9]{64}$/'], 'page' => ['nullable', 'integer', 'min:1']]);
        $page = $resources->duplicates($data['sha256'])->paginate(30);

        return $this->FormatPage($page, $page->items());
    }

    public function reference(Request $request, Services\AudioResources $resources)
    {
        $data = $request->validate(['request_id' => ['required', 'uuid'], 'upload_id' => ['nullable', 'uuid'], 'work_id' => ['required', 'integer'], 'reference_media_id' => ['required', 'integer'], 'sha256' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'file_name' => ['required', 'string', 'max:255'], 'file_size' => ['required', 'integer', 'min:1'], 'chapter_id' => ['nullable', 'integer'],
            'season_number' => ['required', 'integer', 'min:0', 'max:1000000'], 'episode_number' => ['required', 'integer', 'min:0', 'max:1000000'],
            'title' => ['nullable', 'string', 'max:255'], 'allow_alternative' => ['sometimes', 'boolean'], 'primary_choice' => ['required_if:allow_alternative,true', 'nullable', Rule::in(['keep', 'new'])]]);

        return ['data' => $resources->reference($request->user(), $data)];
    }

    public function store(Request $request, Services\Uploads $uploads)
    {
        $data = $request->validate([
            'work_id' => ['nullable', 'integer'], 'author_id' => ['nullable', 'integer'], 'kind' => ['required', Rule::in(['audio', 'cover'])],
            'file_name' => ['required', 'string', 'max:255', function ($attribute, $value, $fail) {
                if (str_contains($value, '/') || str_contains($value, chr(92)) || preg_match('/[[:cntrl:]]/u', $value)) {
                    $fail('文件名不能含路径或控制字符。');
                }
            }],
            'file_size' => ['required', 'integer', 'min:1', 'max:'.config($request->input('kind') === 'cover' ? 'contribution.max_cover_size' : 'contribution.max_file_size')],
            'sha256' => ['nullable', 'regex:/^[a-f0-9]{64}$/'],
            'season_number' => ['required', 'integer', 'min:0', 'max:1000000'],
            'episode_number' => ['required', 'integer', 'min:0', 'max:1000000'],
            'chapter_id' => ['nullable', 'integer'],
            'allow_alternative' => ['sometimes', 'boolean'],
            'primary_choice' => ['required_if:allow_alternative,true', 'nullable', Rule::in(['keep', 'new'])],
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(['data' => $uploads->start($request->user(), $data)], 201);
    }

    public function show(Request $request, string $upload, Services\Uploads $uploads)
    {
        return ['data' => $uploads->describe($uploads->session($upload, $request->user()))];
    }

    public function chunk(Request $request, string $upload, int $index, Services\Uploads $uploads)
    {
        $request->validate(['chunk' => ['required', 'file', 'max:'.(config('contribution.max_chunk_size') / 1024)]]);

        return $uploads->chunk($upload, $request->user(), $index, $request->file('chunk'));
    }

    public function complete(Request $request, string $upload, Services\Uploads $uploads)
    {
        return ['data' => $uploads->complete($upload, $request->user())];
    }

    public function destroy(Request $request, string $upload, Services\Uploads $uploads)
    {
        $uploads->cancel($upload, $request->user());

        return ['message' => '这次上传已取消。'];
    }
}
