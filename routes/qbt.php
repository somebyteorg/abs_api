<?php

use App\Http\Controllers\QbtController;
use Illuminate\Support\Facades\Route;

Route::prefix('qbt')->name('qbt.')->middleware(['auth', 'role:admin'])->group(function () {
    Route::get('/', [QbtController::class, 'index'])->name('index');
    Route::get('works', [QbtController::class, 'works'])->name('works');
    Route::get('directories', [QbtController::class, 'directories'])->name('directories');
    Route::get('files', [QbtController::class, 'files'])->name('files');
    Route::post('import', [QbtController::class, 'import'])->name('import');
});
