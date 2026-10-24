<?php

use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SessionsController;
use App\Http\Controllers\Settings\TwoFactorController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/two-factor', [TwoFactorController::class, 'show'])->name('two-factor.show');
    Route::post('settings/two-factor', [TwoFactorController::class, 'confirm'])->name('two-factor.confirm');
    Route::post('settings/two-factor/recovery-codes', [TwoFactorController::class, 'regenerate'])->name('two-factor.recovery');
    Route::delete('settings/two-factor', [TwoFactorController::class, 'destroy'])->name('two-factor.destroy');

    Route::get('settings/sessions', [SessionsController::class, 'index'])->name('sessions.index');
    Route::delete('settings/sessions', [SessionsController::class, 'destroyOthers'])->name('sessions.destroy-others');
    Route::delete('settings/sessions/{id}', [SessionsController::class, 'destroy'])->name('sessions.destroy');

});
