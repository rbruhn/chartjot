<?php

use App\Livewire\Actions\Logout;
use Livewire\Volt\Component;

new class extends Component
{
    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

<nav x-data="{ open: false }" class="bg-white dark:bg-gray-800 border-b-2 border-gray-100 dark:border-gray-700">
    <!-- Primary Navigation Menu -->
    <div class="px-6">
        <div class="flex justify-between h-16">
            <div class="flex">
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('journal.index') }}" class="text-lg font-semibold text-gray-900 dark:text-gray-100" wire:navigate>
                        {{ auth()->user()->name }}'s Trade Journal
                    </a>
                </div>

                <!-- Navigation Links -->
                <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                    <x-nav-link :href="route('journal.index')" :active="request()->routeIs('journal.index')" wire:navigate>
                        {{ __('Journal') }}
                    </x-nav-link>
                    <x-nav-link :href="route('journal.statistics')" :active="request()->routeIs('journal.statistics')" wire:navigate>
                        {{ __('Statistics') }}
                    </x-nav-link>
                    <x-nav-link :href="route('journal.accounts')" :active="request()->routeIs('journal.accounts')" wire:navigate>
                        {{ __('Accounts') }}
                    </x-nav-link>
                    @unless (config('chartjot.self_hosted'))
                    <x-nav-link :href="route('friends.index')" :active="request()->routeIs('friends.index')" wire:navigate>
                        {{ __('Friends') }}
                    </x-nav-link>
                    @endunless
                    @if (! config('chartjot.self_hosted') && auth()->user()?->isAdmin())
                        <x-nav-link :href="route('admin.users')" :active="request()->routeIs('admin.*')" wire:navigate>
                            {{ __('Admin') }}
                        </x-nav-link>
                        <x-nav-link href="/horizon" target="_blank">
                            {{ __('Horizon') }}
                        </x-nav-link>
                    @endif
                </div>
            </div>

            <!-- Settings Dropdown -->
            <div class="hidden sm:flex sm:items-center sm:ms-6">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 dark:text-gray-400 bg-white dark:bg-gray-800 hover:text-gray-700 dark:hover:text-gray-300 focus:outline-none transition ease-in-out duration-150">
                            <div x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></div>

                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile')" wire:navigate>
                            {{ __('Profile') }}
                        </x-dropdown-link>

                        <x-dropdown-link :href="route('journal.settings.edit')" wire:navigate>
                            {{ __('Journal Settings') }}
                        </x-dropdown-link>

                        <!-- Theme -->
                        <div class="border-t border-gray-100 dark:border-gray-600 mt-1 pt-1 px-4 py-2 flex items-center justify-between">
                            <span class="text-sm text-gray-700 dark:text-gray-300">{{ __('Theme') }}</span>
                            <div class="flex rounded-md overflow-hidden border border-gray-300 dark:border-gray-600">
                                <button type="button"
                                    @click="$store.theme.set(false)"
                                    :class="!$store.theme.dark ? 'bg-indigo-600 text-white' : 'bg-white dark:bg-gray-700 text-gray-500 dark:text-gray-300'"
                                    class="px-2 py-1 text-xs font-medium transition">
                                    {{ __('Light') }}
                                </button>
                                <button type="button"
                                    @click="$store.theme.set(true)"
                                    :class="$store.theme.dark ? 'bg-indigo-600 text-white' : 'bg-white dark:bg-gray-700 text-gray-500 dark:text-gray-300'"
                                    class="px-2 py-1 text-xs font-medium transition">
                                    {{ __('Dark') }}
                                </button>
                            </div>
                        </div>

                        <!-- Authentication -->
                        @unless (config('chartjot.self_hosted'))
                        <button wire:click="logout" class="w-full text-start">
                            <x-dropdown-link>
                                {{ __('Log Out') }}
                            </x-dropdown-link>
                        </button>
                        @endunless
                    </x-slot>
                </x-dropdown>
            </div>

            <!-- Hamburger -->
            <div class="-me-2 flex items-center sm:hidden">
                <button @click="open = ! open" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 dark:text-gray-500 hover:text-gray-500 dark:hover:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-900 focus:outline-none focus:bg-gray-100 dark:focus:bg-gray-900 focus:text-gray-500 dark:focus:text-gray-400 transition duration-150 ease-in-out">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">
        <div class="pt-2 pb-3 space-y-1">
            <x-responsive-nav-link :href="route('journal.index')" :active="request()->routeIs('journal.index')" wire:navigate>
                {{ __('Journal') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('journal.statistics')" :active="request()->routeIs('journal.statistics')" wire:navigate>
                {{ __('Statistics') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('journal.accounts')" :active="request()->routeIs('journal.accounts')" wire:navigate>
                {{ __('Accounts') }}
            </x-responsive-nav-link>
            @unless (config('chartjot.self_hosted'))
            <x-responsive-nav-link :href="route('friends.index')" :active="request()->routeIs('friends.index')" wire:navigate>
                {{ __('Friends') }}
            </x-responsive-nav-link>
            @endunless
            @if (! config('chartjot.self_hosted') && auth()->user()?->isAdmin())
                <x-responsive-nav-link :href="route('admin.users')" :active="request()->routeIs('admin.*')" wire:navigate>
                    {{ __('Admin') }}
                </x-responsive-nav-link>
                <x-responsive-nav-link href="/horizon" target="_blank">
                    {{ __('Horizon') }}
                </x-responsive-nav-link>
            @endif
        </div>

        <!-- Responsive Settings Options -->
        <div class="pt-4 pb-1 border-t border-gray-200 dark:border-gray-600">
            <div class="px-4">
                <div class="font-medium text-base text-gray-800 dark:text-gray-200" x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></div>
                <div class="font-medium text-sm text-gray-500">{{ auth()->user()->email }}</div>
            </div>

            <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('profile')" wire:navigate>
                    {{ __('Profile') }}
                </x-responsive-nav-link>

                <x-responsive-nav-link :href="route('journal.settings.edit')" wire:navigate>
                    {{ __('Journal Settings') }}
                </x-responsive-nav-link>

                <!-- Theme -->
                <div class="mt-2 px-4 py-2 flex items-center justify-between">
                    <span class="text-sm text-gray-600 dark:text-gray-400">{{ __('Theme') }}</span>
                    <div class="flex rounded-md overflow-hidden border border-gray-300 dark:border-gray-600">
                        <button type="button"
                            @click="$store.theme.set(false)"
                            :class="!$store.theme.dark ? 'bg-indigo-600 text-white' : 'bg-white dark:bg-gray-700 text-gray-500 dark:text-gray-300'"
                            class="px-2 py-1 text-xs font-medium transition">
                            {{ __('Light') }}
                        </button>
                        <button type="button"
                            @click="$store.theme.set(true)"
                            :class="$store.theme.dark ? 'bg-indigo-600 text-white' : 'bg-white dark:bg-gray-700 text-gray-500 dark:text-gray-300'"
                            class="px-2 py-1 text-xs font-medium transition">
                            {{ __('Dark') }}
                        </button>
                    </div>
                </div>

                <!-- Authentication -->
                @unless (config('chartjot.self_hosted'))
                <button wire:click="logout" class="w-full text-start">
                    <x-responsive-nav-link>
                        {{ __('Log Out') }}
                    </x-responsive-nav-link>
                </button>
                @endunless
            </div>
        </div>
    </div>
</nav>
