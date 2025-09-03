<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers;

Route::get('/', [Controllers\Index::class, 'start']);
