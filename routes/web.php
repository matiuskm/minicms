<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\PageController;
use App\Http\Controllers\Admin\PostController;
use App\Http\Controllers\Admin\TagController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\ContentPreviewController;
use App\Http\Controllers\MediaController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

// Public PDF downloads (attachment-only, sandboxed).
Route::get('files/{uuid}', [MediaController::class, 'file'])->name('files.show');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    // Media: writers see/manage only their own files (scoped in the controller).
    Route::get('media', [MediaController::class, 'index'])->name('media.index');
    Route::post('media', [MediaController::class, 'store'])->middleware('throttle:upload')->name('media.store');
    Route::put('media/{id}', [MediaController::class, 'update'])->name('media.update');
    Route::delete('media/{id}', [MediaController::class, 'destroy'])->name('media.destroy');

    // Posts: admins and writers; the policy and data scope decide what each may do.
    Route::prefix('admin/posts')->name('admin.posts.')->group(function () {
        Route::get('/', [PostController::class, 'index'])->name('index');
        Route::get('create', [PostController::class, 'create'])->name('create');
        Route::post('/', [PostController::class, 'store'])->name('store');
        Route::get('{id}', [PostController::class, 'edit'])->whereNumber('id')->name('edit');
        Route::put('{id}', [PostController::class, 'update'])->whereNumber('id')->name('update');
        Route::put('{id}/autosave', [PostController::class, 'autosave'])->middleware('throttle:120,1')->name('autosave');
        Route::post('{id}/reject', [PostController::class, 'reject'])->name('reject');
        Route::post('{id}/unpublish', [PostController::class, 'unpublish'])->name('unpublish');
        Route::post('{id}/archive', [PostController::class, 'archive'])->name('archive');
        Route::post('{id}/unarchive', [PostController::class, 'unarchive'])->name('unarchive');
        Route::post('{id}/restore', [PostController::class, 'restore'])->name('restore');
        Route::delete('{id}', [PostController::class, 'destroy'])->name('destroy');
        Route::delete('{id}/purge', [PostController::class, 'purge'])->name('purge');
        Route::get('{id}/revisions', [PostController::class, 'revisions'])->name('revisions');
        Route::post('{id}/revisions/{revision}/restore', [PostController::class, 'restoreRevision'])->name('revisions.restore');
    });

    Route::get('tags/search', [TagController::class, 'search'])->name('tags.search');
    Route::post('tags/find-or-create', [TagController::class, 'findOrCreate'])->name('tags.find-or-create');
    Route::post('content/preview', ContentPreviewController::class)->middleware('throttle:preview')->name('content.preview');
});

Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::post('users/{user}/disable', [UserController::class, 'disable'])->name('users.disable');
    Route::post('users/{user}/enable', [UserController::class, 'enable'])->name('users.enable');
    Route::post('users/{user}/force-reset', [UserController::class, 'forceReset'])->name('users.force-reset');
    Route::post('users/{user}/resend-invite', [UserController::class, 'resendInvite'])->name('users.resend-invite');

    Route::prefix('pages')->name('pages.')->group(function () {
        Route::get('/', [PageController::class, 'index'])->name('index');
        Route::get('create', [PageController::class, 'create'])->name('create');
        Route::post('/', [PageController::class, 'store'])->name('store');
        Route::get('{id}', [PageController::class, 'edit'])->whereNumber('id')->name('edit');
        Route::put('{id}', [PageController::class, 'update'])->whereNumber('id')->name('update');
        Route::post('{id}/unpublish', [PageController::class, 'unpublish'])->name('unpublish');
        Route::post('{id}/restore', [PageController::class, 'restore'])->name('restore');
        Route::delete('{id}', [PageController::class, 'destroy'])->name('destroy');
        Route::delete('{id}/purge', [PageController::class, 'purge'])->name('purge');
        Route::get('{id}/revisions', [PageController::class, 'revisions'])->name('revisions');
        Route::post('{id}/revisions/{revision}/restore', [PageController::class, 'restoreRevision'])->name('revisions.restore');
    });

    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::put('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
    Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

    Route::get('tags', [TagController::class, 'index'])->name('tags.index');
    Route::post('tags', [TagController::class, 'store'])->name('tags.store');
    Route::post('tags/merge', [TagController::class, 'merge'])->name('tags.merge');
    Route::put('tags/{tag}', [TagController::class, 'update'])->name('tags.update');
    Route::delete('tags/{tag}', [TagController::class, 'destroy'])->name('tags.destroy');

    Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
    Route::get('audit-logs/export', [AuditLogController::class, 'export'])->name('audit-logs.export');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
