{{--
    "View image" link for an image attached to a trade comment. The image is
    not shown in the thread — only in the modal the link opens.
--}}
@props(['id', 'url'])

<button type="button" onclick="document.getElementById('comment-image-{{ $id }}').showModal()"
    class="inline-flex items-center gap-1 mt-1 text-xs text-indigo-600 dark:text-indigo-400 hover:underline">
    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
    </svg>
    View image
</button>
<x-image-dialog :id="'comment-image-'.$id" :url="$url" alt="Image attached to a comment" />
