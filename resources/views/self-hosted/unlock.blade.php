<x-guest-layout>
    <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
        {{ __('Enter the journal password to open :app on this browser.', ['app' => config('app.name')]) }}
    </p>

    <form method="POST" action="{{ route('self-hosted.unlock.store') }}">
        @csrf

        <div>
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" class="block mt-1 w-full" type="password" name="password" required autofocus autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end mt-4">
            <x-primary-button>
                {{ __('Unlock') }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
