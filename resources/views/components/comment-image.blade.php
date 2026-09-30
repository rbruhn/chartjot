{{--
    "View image" link for an image attached to a trade comment. The image is
    not shown in the thread: it sits in a closed native <dialog> (loaded
    lazily, so it isn't fetched until opened) and opens as a modal. Plain
    HTML + inline handlers, so it works on the Livewire-free conversation
    page and in the journal alike. Click the backdrop or Close to dismiss.
--}}
@props(['id', 'url'])

<button type="button" onclick="document.getElementById('comment-image-{{ $id }}').showModal()"
    class="inline-flex items-center gap-1 mt-1 text-xs text-indigo-600 dark:text-indigo-400 hover:underline">
    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
    </svg>
    View image
</button>
<dialog id="comment-image-{{ $id }}" onclick="if (event.target === this) this.close()"
    class="p-0 rounded-lg bg-white dark:bg-gray-900 backdrop:bg-black/70" style="max-width:92vw;max-height:92vh">
    <div class="flex justify-end px-2 pt-2">
        <form method="dialog"><button class="text-xs px-3 py-1 rounded border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300">Close</button></form>
    </div>
    <img src="{{ $url }}" loading="lazy" alt="Image attached to a comment" class="block mx-auto p-2" style="max-width:90vw;max-height:84vh">
</dialog>
