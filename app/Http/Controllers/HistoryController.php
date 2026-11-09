<?php

namespace App\Http\Controllers;

use App\Services;
use Illuminate\Http\Request;

class HistoryController extends Controller
{
    public function index(Request $request, Services\ActivityHistory $history)
    {
        $data = $request->validate([
            'user' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'work_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        abort_if(! empty($data['from']) && ! empty($data['to']) && $data['to'] < $data['from'], 422, '结束日期不能早于开始日期。');
        $page = $history->page($request->user(), $data);

        return $this->FormatPage($page, $page->items());
    }

    public function preview(Request $request, int $history, Services\HistoryRestore $restore)
    {
        return $restore->preview($request->user(), $history);
    }

    public function restore(Request $request, int $history, Services\HistoryRestore $restore)
    {
        $data = $request->validate(['expected_revisions' => ['required', 'array', 'min:1'], 'expected_revisions.*' => ['required', 'integer', 'min:0']]);

        return $restore->restore($request->user(), $history, $data['expected_revisions']);
    }
}
