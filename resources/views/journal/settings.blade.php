<div>
    <!-- The only way to do great work is to love what you do. - Steve Jobs -->
</div>
<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                Journal settings
            </h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                Configure your journal and NinjaTrader intake credentials.
            </p>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="rounded-md bg-green-50 p-4 text-sm text-green-800 dark:bg-green-900/30 dark:text-green-200">
                    {{ session('status') }}
                </div>
            @endif

            @if (blank($journal->timezone))
                <div class="rounded-md bg-yellow-50 p-4 dark:bg-yellow-900/30">
                    <div class="flex gap-3">
                        <svg class="h-5 w-5 flex-shrink-0 text-yellow-500 dark:text-yellow-400 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                        </svg>
                        <div>
                            <p class="text-sm font-semibold text-yellow-800 dark:text-yellow-300">Time zone required before importing</p>
                            <p class="mt-1 text-sm text-yellow-700 dark:text-yellow-400">
                                All trade timestamps are stored in UTC. Without a time zone, imports cannot convert
                                NinjaTrader's local times correctly and will be rejected.
                                Set your time zone below and save before importing.
                            </p>
                        </div>
                    </div>
                </div>
            @endif

            <div class="rounded-lg bg-white p-6 shadow-sm dark:bg-gray-800">
                <form method="POST" action="{{ route('journal.settings.update') }}" class="space-y-6">
                    @csrf
                    @method('PUT')

                    <div>
                        <x-input-label for="name" value="Journal name" />
                        <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $journal->name)" required autofocus />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="timezone-search" value="Time zone" />
                        <livewire:components.timezone-picker :timezone="old('timezone', $journal->timezone ?? '')" />
                        <x-input-error :messages="$errors->get('timezone')" class="mt-2" />
                    </div>

                    <x-primary-button>Save settings</x-primary-button>
                </form>
            </div>

            <div class="rounded-lg bg-white p-6 shadow-sm dark:bg-gray-800">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Import executions</h3>
                <p class="mt-1 mb-5 text-sm text-gray-600 dark:text-gray-400">
                    Upload an NT8 Trade Performance → Executions CSV to import missed or historical trades.
                    Duplicate trades are skipped automatically. Accounts must already exist on the
                    <a href="{{ route('journal.accounts') }}" wire:navigate class="underline hover:no-underline">Accounts page</a>
                    with matching names — trades for unknown accounts will fail to import.
                </p>
                <livewire:journal.csv-import :journal="$journal" />
            </div>

            <div class="rounded-lg bg-white p-6 shadow-sm dark:bg-gray-800">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Import MAE/MFE</h3>
                <p class="mt-1 mb-5 text-sm text-gray-600 dark:text-gray-400">
                    Adds leg-level MAE/MFE to trades you've <strong>already imported</strong> from the Executions export above —
                    it doesn't import trades itself. Upload the NT8 Trade Performance → <strong>Trades</strong> CSV covering the
                    same period, with the grid's display unit set to Currency. Re-uploading the same file is safe.
                </p>
                <livewire:journal.trades-mae-mfe-import :journal="$journal" />
            </div>

            <div class="rounded-lg bg-white p-6 shadow-sm dark:bg-gray-800">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">NinjaTrader intake</h3>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                            Send completed trades to this URL with your journal intake token as a bearer token.
                        </p>
                    </div>

                    <form method="POST" action="{{ route('journal.settings.token.rotate') }}">
                        @csrf
                        <x-secondary-button type="submit">Generate new token</x-secondary-button>
                    </form>
                </div>

                <div class="mt-5 space-y-4">
                    <div>
                        <x-input-label for="ingest-url" value="Endpoint URL" />
                        <x-copy-input id="ingest-url" class="mt-1" :value="url('/api/v1/trades')" label="Copy endpoint URL" />
                    </div>

                    @if ($ingestToken)
                        <div>
                            <x-input-label for="ingest-token" value="New intake token" />
                            <x-copy-input id="ingest-token" class="mt-1" :value="$ingestToken" label="Copy token" />
                            <p class="mt-2 text-sm text-amber-700 dark:text-amber-300">
                                Copy this token now. It will not be shown again.
                            </p>
                        </div>
                    @else
                        <p class="rounded-md bg-gray-50 p-4 text-sm text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                            For security, existing intake tokens are never displayed. Generate a new token when you need to configure or replace your NT8 AddOn credential.
                        </p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
