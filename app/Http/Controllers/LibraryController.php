<?php

namespace App\Http\Controllers;

use App\Models;
use App\Services;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LibraryController extends Controller
{
    public function index()
    {
        return ['data' => Models\Library::query()->select(['id', 'name', 'type'])->withCount('works')->orderBy('id')->get()];
    }

    public function store(Request $request, Services\Activity $activity, Services\SyncScheduler $sync)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:50'], 'type' => ['required', Rule::in(['book', 'podcast'])]]);

        return DB::transaction(function () use ($request, $activity, $sync, $data) {
            $library = Models\Library::create([...$data, 'user_id' => $request->user()->id, 'abs_id' => (string) Str::uuid(), 'abs_folder_id' => (string) Str::uuid()]);
            $activity->record($request->user(), 'library.created', $library->name, details: ['type' => $library->type, 'library_id' => $library->id]);
            $sync->markLibrary($library);

            return response()->json(['data' => $library], 201);
        });
    }

    public function update(Request $request, int $library, Services\Activity $activity, Services\SyncScheduler $sync, Services\EntityRestore $restore)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:50'], 'type' => ['missing']]);

        return DB::transaction(function () use ($request, $library, $activity, $sync, $restore, $data) {
            $entry = Models\Library::query()->select(Models\Library::FIELDS)->lockForUpdate()->findOrFail($library);
            if ($entry->name === $data['name']) {
                return ['data' => $entry];
            }
            $before = $entry->name;
            $entry->update($data);
            $activity->record($request->user(), 'library.updated', $entry->name, details: ['library_id' => $library, 'before' => $before, 'after' => $entry->name, ...$restore->details('library', $library, ['name' => $before], ['name' => $entry->name])]);
            $sync->markLibrary($entry);

            return ['data' => $entry];
        });
    }

    public function destroy(Request $request, int $library, Services\Activity $activity, Services\SyncScheduler $sync, Services\EntityRestore $restore)
    {
        return DB::transaction(function () use ($request, $library, $activity, $sync, $restore) {
            $entry = Models\Library::query()->select(Models\Library::FIELDS)->lockForUpdate()->findOrFail($library);
            abort_if($entry->works()->exists(), 422, '分类下还有作品，请先移动作品再删除分类。');
            $entry->delete();
            $sync->markLibrary($entry);
            $activity->record($request->user(), 'library.deleted', $entry->name, details: ['library_id' => $library, ...$restore->details('library', $library, ['deleted_at' => null], ['deleted_at' => $entry->deleted_at->toJSON()])]);

            return ['message' => '空分类已删除，历史记录保留。'];
        });
    }
}
