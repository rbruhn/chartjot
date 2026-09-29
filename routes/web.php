<?php

use App\Http\Controllers\JournalAccountsController;
use App\Http\Controllers\JournalController;
use App\Http\Controllers\JournalSettingsController;
use App\Http\Controllers\TradeScreenshotController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::redirect('/', '/journal');

Route::get('journal', [JournalController::class, 'index'])
    ->middleware(['auth', 'active', 'not-admin'])
    ->name('journal.index');

Route::get('accounts', [JournalAccountsController::class, 'index'])
    ->middleware(['auth', 'active', 'not-admin'])
    ->name('journal.accounts');

Route::get('journal/settings', [JournalSettingsController::class, 'edit'])
    ->middleware(['auth', 'active', 'not-admin'])
    ->name('journal.settings.edit');

Route::put('journal/settings', [JournalSettingsController::class, 'update'])
    ->middleware(['auth', 'active', 'not-admin'])
    ->name('journal.settings.update');

Route::post('journal/settings/ingest-token', [JournalSettingsController::class, 'rotateToken'])
    ->middleware(['auth', 'active', 'not-admin'])
    ->name('journal.settings.token.rotate');

Route::get('journal/trades/{trade:uuid}/screenshots/{screenshot}', [TradeScreenshotController::class, 'show'])
    ->middleware(['auth', 'active', 'not-admin'])
    ->name('journal.screenshot');

Route::redirect('dashboard', '/journal')
    ->middleware(['auth', 'active'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth', 'active'])
    ->name('profile');

Route::middleware(['auth', 'active', 'admin'])->group(function () {
    Volt::route('admin/users', 'admin.users')->name('admin.users');
});

require __DIR__.'/auth.php';
