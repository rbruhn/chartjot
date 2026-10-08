<?php

use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// Accounts and logins only exist with several users; self-hosted mode
// (issue #102) has no login, so these are all 404 there.
Route::middleware(['multi-user', 'guest'])->group(function () {
    Volt::route('register', 'pages.auth.register')
        ->middleware(['registration', 'throttle:6,1'])
        ->name('register');

    Volt::route('register/pending', 'pages.auth.pending-approval')
        ->name('register.pending');

    Volt::route('login', 'pages.auth.login')
        ->name('login');

    Volt::route('forgot-password', 'pages.auth.forgot-password')
        ->middleware('throttle:6,1')
        ->name('password.request');

    Volt::route('reset-password/{token}', 'pages.auth.reset-password')
        ->middleware('throttle:6,1')
        ->name('password.reset');
});

Route::middleware(['multi-user', 'auth'])->group(function () {
    Volt::route('verify-email', 'pages.auth.verify-email')
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Volt::route('confirm-password', 'pages.auth.confirm-password')
        ->name('password.confirm');
});
