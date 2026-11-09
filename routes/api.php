<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AuthorController;
use App\Http\Controllers\ChapterController;
use App\Http\Controllers\ContributionController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\WorkController;
use Illuminate\Support\Facades\Route;

Route::get('session', [AuthController::class, 'session']);
Route::get('sign', [AuthController::class, 'sign'])->middleware('throttle:20,1');
Route::get('login', [AuthController::class, 'login'])->middleware('throttle:20,1');

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::post('notice/accept', [AuthController::class, 'acceptNotice']);
    Route::patch('profile', [AuthController::class, 'profile']);
    Route::get('authors', [AuthorController::class, 'index']);
    Route::get('authors/{author}', [AuthorController::class, 'show'])->whereNumber('author');
    Route::delete('authors/{author}', [AuthorController::class, 'destroy'])->whereNumber('author');
    Route::get('libraries/manage', [LibraryController::class, 'index'])->middleware('role:edit');
    Route::get('libraries', [LibraryController::class, 'index']);
    Route::get('works', [WorkController::class, 'index']);
    Route::get('works/{work}/chapters', [ChapterController::class, 'index'])->whereNumber('work');
    Route::get('works/{work}/chapter-directory', [ChapterController::class, 'directory'])->middleware('role:user')->whereNumber('work');
    Route::get('works/{work}/chapter-page', [WorkController::class, 'chapterPage'])->whereNumber('work');
    Route::get('works/{work}/status', [WorkController::class, 'status'])->whereNumber('work');
    Route::get('works/{work}', [WorkController::class, 'show'])->whereNumber('work');
    Route::delete('works/{work}', [WorkController::class, 'destroy'])->whereNumber('work');
    Route::get('file/{user}/{file}', [MediaController::class, 'file'])->whereUuid('file')->whereNumber('user');
    Route::get('history', [HistoryController::class, 'index']);
    Route::get('history/{history}/restore', [HistoryController::class, 'preview'])->middleware('role:edit')->whereNumber('history');
    Route::post('history/{history}/restore', [HistoryController::class, 'restore'])->middleware('role:edit')->whereNumber('history');
    Route::get('uploads', [ContributionController::class, 'uploads']);
    Route::get('stats', [ContributionController::class, 'stats']);
    Route::get('inbox', [InboxController::class, 'index']);
    Route::patch('inbox/{message}/read', [InboxController::class, 'read'])->whereNumber('message');

    Route::middleware('role:user')->group(function () {
        Route::post('uploads/duplicates', [UploadController::class, 'duplicates']);
        Route::post('uploads/reference', [UploadController::class, 'reference']);
        Route::post('authors', [AuthorController::class, 'store']);
        Route::patch('authors/{author}', [AuthorController::class, 'update'])->whereNumber('author');
        Route::post('works', [WorkController::class, 'store']);
        Route::post('works/{work}/chapters', [ChapterController::class, 'store'])->whereNumber('work');
        Route::post('works/{work}/organize/preview', [ChapterController::class, 'preview'])->whereNumber('work');
        Route::post('works/{work}/organize/{plan}', [ChapterController::class, 'apply'])->whereNumber('work')->whereUuid('plan');
        Route::patch('works/{work}/chapters/{chapter}', [ChapterController::class, 'update'])->whereNumber(['work', 'chapter']);
        Route::put('works/{work}/chapters/{chapter}/primary', [ChapterController::class, 'primary'])->whereNumber(['work', 'chapter']);
        Route::delete('works/{work}/chapters/{chapter}', [ChapterController::class, 'destroy'])->whereNumber(['work', 'chapter']);
        Route::patch('works/{work}', [WorkController::class, 'update'])->whereNumber('work');
        Route::put('works/{work}/order', [WorkController::class, 'reorder'])->whereNumber('work');
        Route::post('works/{work}/sync', [WorkController::class, 'retry'])->whereNumber('work');
        Route::delete('media/{media}', [MediaController::class, 'destroy'])->whereNumber('media');
        Route::post('uploads', [UploadController::class, 'store'])->middleware('throttle:120,1');
        Route::get('uploads/{upload}', [UploadController::class, 'show'])->whereUuid('upload');
        Route::post('uploads/{upload}/chunks/{index}', [UploadController::class, 'chunk'])->whereUuid('upload')->whereNumber('index');
        Route::post('uploads/{upload}/complete', [UploadController::class, 'complete'])->whereUuid('upload');
        Route::delete('uploads/{upload}', [UploadController::class, 'destroy'])->whereUuid('upload');
    });
    Route::patch('libraries/{library}', [LibraryController::class, 'update'])->middleware('role:edit')->whereNumber('library');
    Route::delete('libraries/{library}', [LibraryController::class, 'destroy'])->middleware('role:edit')->whereNumber('library');
    Route::post('libraries', [LibraryController::class, 'store'])->middleware('role:edit');
    Route::get('users', [MemberController::class, 'index'])->middleware('role:admin');
    Route::patch('users/{user}/role', [MemberController::class, 'updateRole'])->middleware('role:admin')->whereNumber('user');
});
