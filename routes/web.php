<?php

use App\Http\Controllers\FriendsController;
use App\Http\Controllers\JournalAccountsController;
use App\Http\Controllers\JournalController;
use App\Http\Controllers\JournalSettingsController;
use App\Http\Controllers\JournalStatisticsController;
use App\Http\Controllers\SharedTradeController;
use App\Http\Controllers\TradeCommentController;
use App\Http\Controllers\TradeScreenshotController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::redirect('/', '/journal');

Route::get('journal', [JournalController::class, 'index'])
    ->middleware(['auth', 'active'])
    ->name('journal.index');

Route::get('journal/statistics', [JournalStatisticsController::class, 'index'])
    ->middleware(['auth', 'active'])
    ->name('journal.statistics');

Route::get('accounts', [JournalAccountsController::class, 'index'])
    ->middleware(['auth', 'active'])
    ->name('journal.accounts');

Route::get('friends', [FriendsController::class, 'index'])
    ->middleware(['multi-user', 'auth', 'active'])
    ->name('friends.index');

Route::get('journal/settings', [JournalSettingsController::class, 'edit'])
    ->middleware(['auth', 'active'])
    ->name('journal.settings.edit');

Route::put('journal/settings', [JournalSettingsController::class, 'update'])
    ->middleware(['auth', 'active'])
    ->name('journal.settings.update');

Route::post('journal/settings/ingest-token', [JournalSettingsController::class, 'rotateToken'])
    ->middleware(['auth', 'active'])
    ->name('journal.settings.token.rotate');

Route::get('journal/trades/{trade:uuid}/screenshots/{screenshot}', [TradeScreenshotController::class, 'show'])
    ->middleware(['auth', 'active'])
    ->name('journal.screenshot');

// Standalone shared-trade page for invited friends (issue #18). Outside the
// journal routes on purpose: its own bare layout, and every request is
// re-authorized by TradePolicy::viewShared / comment.
Route::middleware(['multi-user', 'auth', 'active'])->group(function () {
    Route::get('trades/{trade:uuid}/shared', [SharedTradeController::class, 'show'])
        ->name('trades.shared');
    Route::get('trades/{trade:uuid}/shared/screenshots/{screenshot}', [SharedTradeController::class, 'screenshot'])
        ->name('trades.shared.screenshot');
    Route::get('trades/{trade:uuid}/shared/comments/{comment}/image', [SharedTradeController::class, 'commentImage'])
        ->name('trades.shared.comment-image');
    // Rate limited inside TradeCommentPoster, shared with the journal page.
    Route::post('trades/{trade:uuid}/shared/comments', [TradeCommentController::class, 'store'])
        ->name('trades.shared.comments.store');
});

Route::redirect('dashboard', '/journal')
    ->middleware(['auth', 'active'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth', 'active'])
    ->name('profile');

Route::middleware(['multi-user', 'auth', 'active', 'admin'])->group(function () {
    Volt::route('admin/users', 'admin.users')->name('admin.users');
});

require __DIR__.'/auth.php';
