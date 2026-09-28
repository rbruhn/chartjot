<?php

use App\Http\Controllers\Api\V1\TradeIntakeController;
use App\Http\Controllers\Api\V1\TradeNoteController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('journal.token')->group(function () {
    Route::post('trades', [TradeIntakeController::class, 'store']);
    Route::post('trades/{trade}/notes', [TradeNoteController::class, 'store']);
});
