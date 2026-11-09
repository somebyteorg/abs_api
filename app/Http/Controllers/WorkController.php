<?php

namespace App\Http\Controllers;

use App\Models;
use App\Services;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'library_id' => ['nullable', 'integer'], 'author_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::in(['book', 'podcast'])], 'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $page = Models\Work::query()->select(Models\Work::CARD_FIELDS)->with(['library:id,name,type', 'creator:id,username,pseudonym', 'authors:id,name,cover_file_id'])
            ->visibleTo($request->user())
            ->withCount('media')->when($data['q'] ?? null, fn ($q, $v) => $q->matching($v))
            ->when($data['library_id'] ?? null, fn ($q, $v) => $q->where('abs_library_id', $v))
            ->when($data['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($data['author_id'] ?? null, fn ($q, $v) => $q->whereHas('authors', fn ($a) => $a->where('abs_authors.id', $v)))
            ->orderByDesc('updated_at')->orderByDesc('id')->paginate(24);

        return $this->FormatPage($page, $page->items());
    }

    public function show(int $work, Services\WorkState $state)
    {
        $entry = Models\Work::query()->select(Models\Work::PUBLIC_FIELDS)->with(['library:id,name,type', 'creator:id,username,pseudonym', 'authors:id,name,cover_file_id'])->findOrFail($work);

        return ['data' => [...$entry->toArray(), ...$state->read($work)]];
    }

    public function chapterPage(Request $request, int $work)
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'filter' => ['nullable', Rule::in(['all', 'active', 'missing', 'multiple'])], 'page' => ['nullable', 'integer', 'min:1']]);
        Models\Work::query()->select(['id'])->findOrFail($work);
        $page = Models\Chapter::query()->select(Models\Chapter::FIELDS)->where('abs_list_id', $work)
            ->matching($data['q'] ?? null)->resourceState($data['filter'] ?? 'all')
            ->withResources()->ordered()->paginate(Models\Chapter::PAGE_SIZE);

        return $this->FormatPage($page, $page->items());
    }

    private function rules(bool $creating): array
    {
        return [
            'name' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:120'],
            'tagline' => ['nullable', 'string', 'max:200'],
            'author_ids' => ['sometimes', 'array', 'max:100'], 'author_ids.*' => ['integer', 'distinct'],
            'description' => ['nullable', 'string', 'max:10000'],
            'cover_file_id' => ['sometimes', 'nullable', 'uuid'],
            'date_air' => ['nullable', 'date_format:Y-m-d'],
            'is_adult' => ['sometimes', 'boolean'],
            'abs_library_id' => [$creating ? 'required' : 'sometimes', 'required', 'integer'],
            'type' => $creating ? ['required', Rule::in(['book', 'podcast'])] : ['missing'],
        ];
    }

    public function store(Request $request, Services\WorkEditor $editor)
    {
        $work = $editor->create($request->user(), $request->validate($this->rules(true)));

        return response()->json(['data' => $work], 201);
    }

    public function update(Request $request, int $work, Services\WorkEditor $editor)
    {
        return ['data' => $editor->update($request->user(), $work, $request->validate($this->rules(false)))];
    }

    public function destroy(Request $request, int $work, Services\WorkEditor $editor)
    {
        $data = $request->validate(['expected_revision' => ['required', 'integer', 'min:0']]);
        $editor->delete($request->user(), $work, $data['expected_revision']);

        return ['message' => '作品已删除，历史记录和上传文件保留。'];
    }

    public function reorder(Request $request, int $work, Services\WorkEditor $editor)
    {
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'],
            'chapter_ids' => ['required', 'array', 'max:20000'],
            'chapter_ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $editor->reorder($request->user(), $work, $data['expected_revision'], $data['chapter_ids']);

        return ['message' => '新顺序已收好。'];
    }

    public function retry(Request $request, int $work, Services\WorkEditor $editor)
    {
        $editor->retrySync($request->user(), $work);

        return ['message' => '已重新排队，稍等一分钟。'];
    }
}
