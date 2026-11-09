<?php

namespace App\Http\Controllers;

use App\Services;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ListeningController extends Controller
{
    public function stats(Request $request, Services\ListeningStats $statistics)
    {
        $options = $request->validate([
            'period' => ['sometimes', Rule::in(['today', 'week', 'month', 'year', 'all', 'custom'])],
            'from' => ['required_if:period,custom', 'date_format:Y-m-d', 'before_or_equal:today'],
            'to' => ['required_if:period,custom', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:today'],
            'type' => ['sometimes', Rule::in(['all', 'book', 'podcast'])],
            'sort' => ['sometimes', Rule::in(['seconds', 'listeners'])],
            'limit' => ['sometimes', 'integer', Rule::in([5, 10, 20])],
        ]);

        return response()->json($statistics->get($request->user(), $options))->header('Cache-Control', 'private, no-store');
    }
}
