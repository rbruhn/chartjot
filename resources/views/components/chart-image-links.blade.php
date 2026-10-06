{{--
    A trade's chart images as named links (#91), one row each, under the Chart
    heading. No image is shown inline: a link opens its image in a modal.
    $images: list of ['id' => int, 'label' => string, 'url' => string], in
    display order (see Trade::chartImages). $deletable adds a delete action to
    each row; it calls the Livewire deleteScreenshot action, so only the
    owner's journal passes it.
--}}
@props(['images', 'deletable' => false])

@if (count($images))
    <div class="rounded-lg border border-gray-200 dark:border-gray-700 divide-y divide-gray-200 dark:divide-gray-700 overflow-hidden">
        @foreach ($images as $image)
            <div class="flex items-center justify-between gap-3 px-3 py-2 bg-gray-50 dark:bg-gray-800">
                <button type="button" data-chart-image="{{ $image['id'] }}"
                    onclick="document.getElementById('chart-image-{{ $image['id'] }}').showModal()"
                    class="flex items-center gap-2 min-w-0 text-sm text-indigo-600 dark:text-indigo-400 hover:underline text-left">
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    <span class="truncate">{{ $image['label'] }}</span>
                </button>
                @if ($deletable)
                    <button type="button"
                        wire:click="deleteScreenshot({{ $image['id'] }})"
                        wire:confirm="Delete this image?"
                        title="Delete image" aria-label="Delete {{ $image['label'] }}"
                        class="flex-shrink-0 p-1 rounded text-gray-400 dark:text-gray-500 hover:text-red-600 dark:hover:text-red-400">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                    </button>
                @endif
            </div>
            <x-image-dialog :id="'chart-image-'.$image['id']" :url="$image['url']" :alt="$image['label']" />
        @endforeach
    </div>
@endif
