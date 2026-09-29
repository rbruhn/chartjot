<?php

use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component
{
    public string $timezone = '';
    public string $query    = '';

    public function mount(string $timezone = ''): void
    {
        $this->timezone = $timezone;
        $this->query    = $timezone;
    }

    #[Computed]
    public function suggestions(): array
    {
        $q = trim($this->query);

        if ($q === '' || $q === $this->timezone) {
            return $this->common();
        }

        $needle = str_replace(' ', '_', $q);

        return array_values(array_slice(
            array_filter(
                timezone_identifiers_list(),
                fn ($tz) => stripos($tz, $needle) !== false
                         || stripos(str_replace('_', ' ', $tz), $q) !== false
            ),
            0, 12
        ));
    }

    public function select(string $tz): void
    {
        if (! in_array($tz, timezone_identifiers_list(), true)) {
            return;
        }

        $this->timezone = $tz;
        $this->query    = $tz;
        $this->dispatch('timezone-selected', timezone: $tz);
    }

    /** Common trading-zone defaults shown before the user types. */
    private function common(): array
    {
        return [
            'America/New_York',
            'America/Chicago',
            'America/Denver',
            'America/Los_Angeles',
            'America/Phoenix',
            'America/Toronto',
            'Europe/London',
            'Europe/Frankfurt',
            'Asia/Tokyo',
            'Asia/Hong_Kong',
            'Asia/Singapore',
            'UTC',
        ];
    }
}; ?>

<div
    x-data="{ open: false }"
    @focusin="open = true"
    @click.outside="open = false"
    @keydown.escape.window="open = false"
    @timezone-selected.window="open = false"
    class="relative"
>
    <x-text-input
        wire:model.live.debounce.200ms="query"
        id="timezone-search"
        type="text"
        class="mt-1 block w-full"
        placeholder="Search… e.g. New York, Chicago, London"
        autocomplete="off"
        spellcheck="false"
    />

    {{-- Hidden input carries the validated timezone value into the parent form --}}
    <input type="hidden" name="timezone" value="{{ $timezone }}">

    @php $suggestions = $this->suggestions @endphp

    @if (count($suggestions))
        <ul
            x-show="open"
            x-transition:enter="transition ease-out duration-100"
            x-transition:enter-start="opacity-0 -translate-y-1"
            x-transition:enter-end="opacity-100 translate-y-0"
            class="absolute z-20 mt-1 max-h-60 w-full overflow-y-auto rounded-md bg-white shadow-lg ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10"
        >
            @foreach ($suggestions as $tz)
                <li
                    wire:click="select('{{ $tz }}')"
                    wire:key="{{ $tz }}"
                    class="flex cursor-pointer items-center justify-between px-4 py-2 text-sm
                        {{ $tz === $timezone
                            ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300'
                            : 'text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700' }}"
                >
                    <span>{{ str_replace('_', ' ', $tz) }}</span>
                    @if ($tz === $timezone)
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                        </svg>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
