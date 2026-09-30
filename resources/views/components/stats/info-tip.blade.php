{{--
    An info icon that explains a panel on demand. Opens on hover, on keyboard
    focus, and on click/tap (which pins it open until clicked again). Closes on
    Escape, a click elsewhere, or focus leaving the icon.

    The bubble is anchored to the icon with position: fixed, so a card's
    overflow-hidden can't clip it, and it flips/shifts to stay on screen at
    narrow widths. (Not teleported: a teleported bubble sits outside the
    Livewire component and errors when the page re-renders.) It lives inside
    headings, so its content should be phrasing markup — <span class="block">
    rather than <p>. Its text is also the icon's accessible description.

    label: the icon's accessible name, e.g. "About Runners"
--}}
@props(['label' => 'About this panel'])

<span x-data="{ open: false, pinned: false }" x-id="['info-tip']"
    class="inline-flex flex-shrink-0 normal-case font-normal tracking-normal"
    @keydown.escape.window="open = pinned = false">
    <button type="button" x-ref="icon"
        class="inline-flex rounded-full text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
        aria-label="{{ $label }}"
        :aria-describedby="$id('info-tip')"
        :aria-expanded="open.toString()"
        @mouseenter="open = true"
        @mouseleave="open = pinned"
        @focus="open = true"
        @blur="open = pinned = false"
        @click="pinned = ! pinned; open = pinned">
        <x-heroicon-o-information-circle class="w-4 h-4" aria-hidden="true" />
    </button>

    <span :id="$id('info-tip')" role="tooltip"
        x-show="open" x-cloak x-transition.opacity.duration.100ms
        x-anchor.fixed.bottom-start.offset.6="$refs.icon"
        @click.outside="if (! $refs.icon.contains($event.target)) open = pinned = false"
        {{ $attributes->class('z-50 block w-max max-w-[min(20rem,calc(100vw-2rem))] rounded-md border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-3 py-2 text-left text-xs leading-relaxed text-gray-700 dark:text-gray-200 shadow-lg space-y-1.5') }}>
        {{ $slot }}
    </span>
</span>
