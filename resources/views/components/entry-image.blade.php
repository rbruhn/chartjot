{{--
    "Entry Image" link for the image captured when the trade opened (#72). The
    entry image is not shown inline — only in the modal the link opens. Any
    slot content (the owner's delete action) is shown in the modal's header.
--}}
@props(['id', 'url'])

<button type="button" onclick="document.getElementById('entry-image-{{ $id }}').showModal()"
    class="inline-flex items-center gap-1 mt-2 text-xs text-indigo-600 dark:text-indigo-400 hover:underline">
    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
    </svg>
    Entry Image
</button>
<x-image-dialog :id="'entry-image-'.$id" :url="$url" alt="Chart when the trade was entered">{{ $slot }}</x-image-dialog>
