{{--
    Modal for viewing an image larger: a native <dialog>, opened with
    document.getElementById(id).showModal(). The <img> is lazy, so an image
    that isn't already on the page isn't fetched until the dialog opens.
    Click the backdrop or Close to dismiss. Optional slot content (e.g. a
    delete action) sits next to Close. Plain HTML — works with or
    without Livewire/Alpine on the page.
--}}
@props(['id', 'url', 'alt' => 'Image'])

<dialog id="{{ $id }}" onclick="if (event.target === this) this.close()"
    class="p-0 rounded-lg bg-white dark:bg-gray-900 backdrop:bg-black/70" style="max-width:92vw;max-height:92vh">
    <div class="flex justify-end items-center gap-2 px-2 pt-2">
        {{ $slot }}
        <form method="dialog"><button class="text-xs px-3 py-1 rounded border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300">Close</button></form>
    </div>
    <img src="{{ $url }}" loading="lazy" alt="{{ $alt }}" class="block mx-auto p-2" style="max-width:90vw;max-height:84vh">
</dialog>
