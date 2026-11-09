<?php

namespace App\Http\Controllers;

use Illuminate\Support\Carbon;

class Index extends Controller
{
    public function start()
    {
        return [
            'name' => config('app.name'),
            'time' => Carbon::now()->toDateTimeString(),
        ];
    }
}
