{{--
    Shared-trade page. Receives only plain arrays ($trade, $screenshots,
    $notes, $comments) built by SharedTradeController — no models.
--}}
@php
    use App\Support\Format;
    $card  = 'rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900';
    $label = 'text-xs font-semibold text-gray-600 dark:text-gray-500 uppercase tracking-wider';
    $input = 'w-full bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100 text-sm rounded px-3 py-2 focus:outline-none focus:ring-1 focus:ring-indigo-500';
    $btn   = 'text-xs px-3 py-1.5 rounded bg-indigo-600 hover:bg-indigo-500 text-white transition-colors';
@endphp

<x-shared-layout :title="$trade['title']">
    {{-- ── Trade ── --}}
    <section class="{{ $card }} p-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold">{{ $trade['title'] }}</h1>
                <p class="text-xs text-gray-600 dark:text-gray-500 mt-1">{{ $trade['date'] }} · shared by {{ $trade['owner_name'] }}</p>
            </div>
            <div class="text-2xl font-bold {{ Format::pnlClass($trade['net_pnl']) }}">{{ Format::pnl($trade['net_pnl']) }}</div>
        </div>
        <dl class="grid grid-cols-3 gap-4 mt-5 bg-gray-50 dark:bg-gray-800 rounded-lg px-5 py-4">
            <div><dt class="{{ $label }}">Entry</dt><dd class="text-base font-semibold mt-0.5 tabular-nums">{{ number_format($trade['entry_price'], 2) }}</dd></div>
            <div><dt class="{{ $label }}">Exit</dt><dd class="text-base font-semibold mt-0.5 tabular-nums">{{ number_format($trade['exit_price'], 2) }}</dd></div>
            <div><dt class="{{ $label }}">Points</dt><dd class="text-base font-semibold mt-0.5 tabular-nums">{{ number_format($trade['points'], 2) }}</dd></div>
        </dl>
    </section>

    {{-- ── Screenshots ── --}}
    @foreach ($screenshots as $i => $shot)
        <figure class="{{ $card }} mt-4 overflow-hidden" style="position:relative">
            <img src="{{ $shot['url'] }}" alt="{{ $shot['caption'] ?? 'Trade screenshot' }}" class="w-full">
            <button type="button" onclick="document.getElementById('chart-image-{{ $i }}').showModal()"
                title="Expand image" aria-label="Expand image"
                class="text-gray-300 hover:text-white" style="position:absolute;top:0.5rem;right:0.5rem;padding:0.375rem;border-radius:0.25rem;background:rgba(0,0,0,0.65);border:none;cursor:pointer;line-height:0">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/>
                </svg>
            </button>
            <x-image-dialog :id="'chart-image-'.$i" :url="$shot['url']" :alt="$shot['caption'] ?? 'Trade screenshot'" />
            @if ($shot['caption'])
                <figcaption class="px-4 py-2 text-xs text-gray-600 dark:text-gray-400">{{ $shot['caption'] }}</figcaption>
            @endif
        </figure>
    @endforeach

    {{-- ── Trader's notes ── --}}
    @if ($notes)
        <section class="{{ $card }} mt-4 p-6">
            <h2 class="{{ $label }} mb-3">Trader's notes</h2>
            <div class="space-y-3">
                @foreach ($notes as $note)
                    <div>
                        <div class="text-[11px] font-medium text-indigo-600 dark:text-indigo-400">{{ $note['phase'] }}</div>
                        <p class="text-sm whitespace-pre-line">{{ $note['body'] }}</p>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- ── Comments ── --}}
    <section class="{{ $card }} mt-4 p-6">
        <h2 class="{{ $label }} mb-3">Comments</h2>

        @if ($errors->any())
            <div class="mb-3 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/30 dark:text-red-300">
                {{ $errors->first() }}
            </div>
        @endif

        <div class="space-y-4">
            @forelse ($comments as $c)
                <article id="comment-{{ $c['id'] }}">
                    <div class="text-sm"><span class="font-semibold">{{ $c['author'] }}</span> <span class="text-xs text-gray-500 dark:text-gray-400">{{ $c['when'] }}</span></div>
                    <p class="text-sm whitespace-pre-line mt-0.5">{{ $c['body'] }}</p>
                    @if ($c['image_url'])
                        <x-comment-image :id="$c['id']" :url="$c['image_url']" />
                    @endif

                    @if ($c['replies'])
                        <div class="mt-3 ml-4 pl-4 border-l-2 border-gray-200 dark:border-gray-700 space-y-3">
                            @foreach ($c['replies'] as $r)
                                <div id="comment-{{ $r['id'] }}">
                                    <div class="text-sm"><span class="font-semibold">{{ $r['author'] }}</span> <span class="text-xs text-gray-500 dark:text-gray-400">{{ $r['when'] }}</span></div>
                                    <p class="text-sm whitespace-pre-line mt-0.5">{{ $r['body'] }}</p>
                                    @if ($r['image_url'])
                                        <x-comment-image :id="$r['id']" :url="$r['image_url']" />
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif

                    {{-- Replies go one level deep: only top-level comments get a reply form. --}}
                    <details class="mt-2 ml-4">
                        <summary class="text-xs text-indigo-600 dark:text-indigo-400 cursor-pointer select-none">Reply</summary>
                        <form method="POST" action="{{ route('trades.shared.comments.store', $trade['uuid']) }}" enctype="multipart/form-data" class="mt-2 space-y-2">
                            @csrf
                            <input type="hidden" name="parent_comment_id" value="{{ $c['id'] }}">
                            <textarea name="body" rows="2" maxlength="5000" required class="{{ $input }}" placeholder="Reply to {{ $c['author'] }}…"></textarea>
                            <label class="block text-xs text-gray-600 dark:text-gray-400">Attach an image (optional, PNG/JPEG up to 10 MB)
                                <input type="file" name="image" accept="image/png,image/jpeg" class="block mt-1 text-xs text-gray-700 dark:text-gray-300">
                            </label>
                            <button type="submit" class="{{ $btn }}">Post reply</button>
                        </form>
                    </details>
                </article>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400">No comments yet.</p>
            @endforelse
        </div>

        <form method="POST" action="{{ route('trades.shared.comments.store', $trade['uuid']) }}" enctype="multipart/form-data" class="mt-6 space-y-2 border-t border-gray-200 dark:border-gray-700 pt-4">
            @csrf
            <label for="new-comment" class="{{ $label }}">Add a comment</label>
            <textarea id="new-comment" name="body" rows="3" maxlength="5000" required class="{{ $input }}">{{ old('parent_comment_id') ? '' : old('body') }}</textarea>
            <label class="block text-xs text-gray-600 dark:text-gray-400">Attach an image (optional, PNG/JPEG up to 10 MB)
                <input type="file" name="image" accept="image/png,image/jpeg" class="block mt-1 text-xs text-gray-700 dark:text-gray-300">
            </label>
            <button type="submit" class="{{ $btn }}">Post comment</button>
        </form>
    </section>
</x-shared-layout>
