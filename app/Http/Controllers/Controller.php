<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

abstract class Controller
{
    public function FormatPage(LengthAwarePaginator $data, iterable $items): array
    {
        return [
            'page' => $data->currentPage(),
            'page_size' => $data->perPage(),
            'total' => $data->total(),
            'items' => $items,
        ];
    }
}
