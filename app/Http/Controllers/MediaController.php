<?php

namespace App\Http\Controllers;

use App\Models;
use App\Services;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MediaController extends Controller
{
    public function destroy(Request $request, int $media, Services\Activity $activity, Services\SyncScheduler $sync, Services\Chapters $chapters, Services\HistoryRestore $restore)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000'], 'replacement_primary_id' => ['nullable', 'integer'], 'expected_revision' => ['required', 'integer', 'min:0']]);
        $resource = Models\Media::query()->select(['id', 'abs_list_id'])->findOrFail($media);

        return DB::transaction(function () use ($request, $media, $resource, $data, $activity, $sync, $chapters, $restore) {
            $work = $chapters->work($resource->abs_list_id, $data['expected_revision']);
            $entry = Models\Media::query()->select(Models\Media::FIELDS)->lockForUpdate()->findOrFail($media);
            abort_unless($entry->abs_list_id === $work->id, 409, '资源已移动，请刷新后再操作。');
            $chapter = $chapters->find($work->id, $entry->abs_item_id);
            $own = $entry->user_id === $request->user()->id;
            abort_unless($own || $request->user()->canEditOthers(), 403, '只能删除自己上传的音频。');
            $reason = trim($data['reason'] ?? '');
            abort_if(! $own && $reason === '', 422, '删除别人的资源，需要写明原因。');
            $nextPrimary = $chapters->primaryAfterRemoval($chapter, $entry->id, $data['replacement_primary_id'] ?? null);
            $snapshot = $restore->capture([$chapter->id], [$entry->id]);
            $chapter->update(['primary_media_id' => $nextPrimary]);
            $entry->update(['delete_reason' => $reason ?: null]);
            $entry->delete();
            Models\StoredFile::reconcileAudio([$entry->file_id]);
            $activity->record($request->user(), 'media.deleted', $entry->name, $work->id, ['media_id' => $entry->id, 'uploader_id' => $entry->user_id, 'reason' => $reason ?: null, ...$restore->details($snapshot, $restore->capture([$chapter->id], [$entry->id]))]);
            if (! $own) {
                Models\InboxMessage::create(['user_id' => $entry->user_id, 'work_id' => $work->id, 'message' => $request->user()->display_name.' 删除了你在「'.$work->name.'」上传的 '.$entry->name.'。原因：'.$reason, 'created_at' => now()]);
            }
            $sync->mark($work);

            return ['message' => '资源已下架，历史贡献会保留。'];
        });
    }

    public function file(Request $request, int $user, string $file)
    {
        abort_unless($request->user()->id === $user, 403, '文件地址中的用户与当前登录用户不一致。');
        $entry = Models\StoredFile::query()->select(['id', 'file_path', 'mime_type'])->findOrFail($file);
        $path = '/'.implode('/', array_map('rawurlencode', explode('/', $entry->absPath())));

        return response('', 200, ['X-Accel-Redirect' => $path, 'Content-Type' => $entry->mime_type, 'X-Content-Type-Options' => 'nosniff']);
    }
}
