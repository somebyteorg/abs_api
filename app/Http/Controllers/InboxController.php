<?php

namespace App\Http\Controllers;

use App\Models;
use Illuminate\Http\Request;

class InboxController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $page = Models\InboxMessage::query()->select(['id', 'work_id', 'message', 'read_at', 'created_at'])
            ->where('user_id', $request->user()->id)->orderByDesc('id')->paginate(30);

        return $this->FormatPage($page, $page->items());
    }

    public function read(Request $request, int $message)
    {
        $row = Models\InboxMessage::query()->select(['id', 'read_at'])->where('user_id', $request->user()->id)->findOrFail($message);
        $row->update(['read_at' => now()]);

        return ['message' => '已读。'];
    }
}
