<?php

use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component {}; ?>

<div class="text-center">
    <div class="mb-4 flex justify-center">
        <svg class="h-12 w-12 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
        </svg>
    </div>

    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
        {{ __('Account Pending Approval') }}
    </h2>

    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
        {{ __('Thanks for registering! Your account is awaiting admin approval.') }}
    </p>

    @if (session('registered_email'))
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            {{ __("We'll send a confirmation to") }}
            <span class="font-medium text-gray-800 dark:text-gray-200">{{ session('registered_email') }}</span>
            {{ __('once your account is approved.') }}
        </p>
    @else
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            {{ __("You'll receive an email once your account is approved.") }}
        </p>
    @endif

    <div class="mt-6">
        <a href="{{ route('login') }}" wire:navigate
           class="inline-flex items-center px-4 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-500 rounded-md font-semibold text-xs text-gray-700 dark:text-gray-300 uppercase tracking-widest shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150">
            {{ __('Back to login') }}
        </a>
    </div>
</div>
