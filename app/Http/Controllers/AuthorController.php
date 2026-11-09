<?php

namespace App\Http\Controllers;

use App\Models;
use App\Services;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthorController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'], 'page_size' => ['sometimes', 'integer', 'min:1', 'max:40']]);
        $page = Models\Author::query()->select(Models\Author::FIELDS)->withCount(['works' => fn ($q) => $q->visibleTo($request->user())])->withExists(['works as has_works' => fn ($q) => $q->withTrashed()])->when($data['q'] ?? null, fn ($q, $v) => $q->whereLike('name', '%'.$v.'%'))->orderBy('name')->orderBy('id')->paginate($data['page_size'] ?? 40);

        return $this->FormatPage($page, $page->items());
    }

    public function show(Request $request, int $author)
    {
        return ['data' => Models\Author::query()->select(Models\Author::FIELDS)->withCount(['works' => fn ($q) => $q->visibleTo($request->user())])->withExists(['works as has_works' => fn ($q) => $q->withTrashed()])->with(['works' => fn ($q) => $q->select(['abs_lists.id', 'abs_lists.name', 'abs_lists.tagline', 'abs_lists.type', 'abs_lists.cover_file_id', 'abs_lists.date_air'])->visibleTo($request->user())->orderByDesc('abs_lists.updated_at')])->findOrFail($author)];
    }

    private function rules(): array
    {
        return ['name' => ['required', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:2000'], 'cover_file_id' => ['sometimes', 'nullable', 'uuid']];
    }

    public function destroy(Request $request, int $author, Services\Activity $activity, Services\EntityRestore $restore)
    {
        return DB::transaction(function () use ($request, $author, $activity, $restore) {
            $entry = Models\Author::query()->select(Models\Author::FIELDS)->lockForUpdate()->findOrFail($author);
            abort_unless($entry->user_id === $request->user()->id || $request->user()->canEditOthers(), 403, '只能删除自己创建的作者，编辑或管理员可以删除其他作者。');
            abort_if(Models\WorkAuthor::query()->where('author_id', $author)->exists(), 422, '作者仍有关联作品，请先解除关联再删除。');
            $entry->delete();
            $activity->record($request->user(), 'author.deleted', $entry->name, details: ['author_id' => $author, ...$restore->details('author', $author, ['deleted_at' => null], ['deleted_at' => $entry->deleted_at->toJSON()])]);

            return ['message' => '作者已删除，可由编辑或管理员从记录恢复。'];
        });
    }

    public function store(Request $request, Services\Activity $activity, Services\Covers $covers)
    {
        $data = $request->validate($this->rules());

        return DB::transaction(function () use ($request, $activity, $covers, $data) {
            $covers->validate($data['cover_file_id'] ?? null, $request->user());
            $author = Models\Author::create([...$data, 'user_id' => $request->user()->id]);
            $covers->attach($author->cover_file_id, authorId: $author->id);
            $activity->record($request->user(), 'author.created', $author->name, details: ['author_id' => $author->id]);

            return response()->json(['data' => $author], 201);
        });
    }

    public function update(Request $request, int $author, Services\Activity $activity, Services\Authors $authors, Services\Covers $covers, Services\EntityRestore $restore)
    {
        $data = $request->validate($this->rules());

        return DB::transaction(function () use ($request, $author, $activity, $authors, $covers, $restore, $data) {
            $entry = Models\Author::query()->select(Models\Author::FIELDS)->lockForUpdate()->findOrFail($author);
            $covers->validate($data['cover_file_id'] ?? null, $request->user(), $entry->cover_file_id);
            $before = $entry->only(['name', 'description', 'cover_file_id']);
            $entry->update($data);
            $covers->attach($entry->cover_file_id, authorId: $entry->id);
            $after = $entry->only(array_keys($before));
            if ($before !== $after) {
                $activity->record($request->user(), 'author.updated', $entry->name, details: ['author_id' => $author, 'before' => $before, 'after' => $after, ...$restore->details('author', $author, $before, $after)]);
            }
            if ($before !== $after) {
                $authors->changed($entry);
            }

            return ['data' => $entry];
        });
    }
}
