<?php

use App\Enums\Direction;
use App\Enums\ExitReason;
use App\Enums\InvitationStatus;
use App\Enums\NotePhase;
use App\Enums\ScreenshotSource;
use App\Mail\TradeInvitationMail;
use App\Models\Friendship;
use App\Models\Journal;
use App\Models\Trade;
use App\Models\TradeInvitation;
use App\Models\User;
use App\Services\TradeCommentPoster;
use App\Models\TradeNote;
use App\Models\TradeScreenshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

new class extends Component {
    use WithFileUploads;

    public Journal $journal;
    public string  $journalName = '';

    #[Url(as: 'from')]
    public string $dateFrom = '';

    #[Url(as: 'to')]
    public string $dateTo = '';

    #[Url]
    public string $search = '';

    #[Url(as: 'accounts')]
    public ?array $selectedAccountIds = null;

    #[Url(as: 'needs_note')]
    public bool $needsNote = false;

    #[Url(as: 'trade')]
    public string $selectedUuid = '';

    public string $newNoteBody  = '';
    public string $newNotePhase = 'post_trade';
    public bool   $addingNote   = false;

    public ?int   $editingNoteId = null;
    public string $editNoteBody  = '';
    public string $editNotePhase = 'post_trade';
    public ?int   $confirmDeleteNoteId = null;

    public mixed $screenshotUpload = null;

    public bool  $editingTrade    = false;

    public bool  $showInvite      = false;

    // Owner's side of the trade's comment thread (issue #18).
    public string $commentBody  = '';
    public mixed  $commentImage = null;
    public ?int   $replyToId    = null;
    public string $replyBody    = '';
    public mixed  $replyImage   = null;
    public array $tradeEditForm   = [];

    public function mount(Journal $journal): void
    {
        $this->journal     = $journal;
        $this->journalName = $journal->name;
        if ($this->dateFrom === '') {
            $this->dateFrom = now()->startOfMonth()->format('Y-m-d');
        }
        if ($this->dateTo === '') {
            $this->dateTo = now()->format('Y-m-d');
        }
    }

    #[Computed]
    public function accounts(): Collection
    {
        return $this->journal->accounts()->orderBy('name')->get();
    }

    #[Computed]
    public function trades(): Collection
    {
        return $this->journal->trades()
            ->with(['account.journal', 'notes', 'screenshot'])
            ->withCount([
                'comments',
                'invitations as active_invitations_count' => fn ($q) => $q->active(),
            ])
            ->when($this->dateFrom, fn ($q) => $q->where('entry_at', '>=', Carbon::parse($this->dateFrom)->startOfDay()))
            ->when($this->dateTo,   fn ($q) => $q->where('entry_at', '<=', Carbon::parse($this->dateTo)->endOfDay()))
            ->when($this->search,   function ($q) {
                $term = $this->search;
                $matchingTypes = collect(\App\Enums\TradeType::cases())
                    ->filter(fn ($t) => str_contains(strtolower($t->label()), strtolower($term)))
                    ->map(fn ($t) => $t->value)
                    ->values()
                    ->all();
                $q->where(fn ($inner) => $inner
                    ->where('instrument_symbol', 'like', "%{$term}%")
                    ->orWhere('instrument', 'like', "%{$term}%")
                    ->orWhere('trade_type', 'like', "%{$term}%")
                    ->when($matchingTypes, fn ($i) => $i->orWhereIn('trade_type', $matchingTypes))
                    ->orWhereHas('account', fn ($a) => $a->where('name', 'like', "%{$term}%"))
                );
            })
            ->when($this->selectedAccountIds !== null, fn ($q) => empty($this->selectedAccountIds)
                ? $q->whereRaw('0 = 1')
                : $q->whereIn('account_id', $this->selectedAccountIds)
            )
            ->when($this->needsNote, fn ($q) => $q->doesntHave('notes'))
            ->orderBy('entry_at', 'desc')
            ->get();
    }

    #[Computed]
    public function grouped(): Collection
    {
        return $this->trades->groupBy(fn (Trade $t) => $t->entry_at_local->format('Y-m-d'));
    }

    #[Computed]
    public function summary(): array
    {
        $trades = $this->trades;
        $total  = $trades->count();
        if ($total === 0) {
            return ['total' => 0, 'net_pnl' => 0.0, 'win_rate' => 0, 'avg_win_pts' => 0.0, 'avg_loss_pts' => 0.0, 'journaled' => 0];
        }
        $winners   = $trades->filter(fn ($t) => (float) $t->net_pnl > 0);
        $losers    = $trades->filter(fn ($t) => (float) $t->net_pnl <= 0);
        $journaled = $trades->filter(fn ($t) => $t->notes->isNotEmpty())->count();

        return [
            'total'        => $total,
            'net_pnl'      => (float) $trades->sum('net_pnl'),
            'win_rate'     => (int) round($winners->count() / $total * 100),
            'avg_win_pts'  => $winners->count() > 0 ? (float) $winners->avg('points') : 0.0,
            'avg_loss_pts' => $losers->count()  > 0 ? -(float) $losers->avg('points') : 0.0,
            'journaled'    => $journaled,
        ];
    }

    #[Computed]
    public function selectedTrade(): ?Trade
    {
        if ($this->selectedUuid === '') return null;
        return $this->journal->trades()
            ->with(['account', 'executions', 'notes', 'screenshots', 'legs'])
            ->where('uuid', $this->selectedUuid)
            ->first();
    }

    public function selectTrade(string $uuid): void
    {
        $this->selectedUuid        = $uuid;
        $this->addingNote          = false;
        $this->editingNoteId       = null;
        $this->confirmDeleteNoteId = null;
        $this->editingTrade        = false;
        $this->tradeEditForm       = [];
        $this->showInvite          = false;
        $this->commentBody         = '';
        $this->replyToId           = null;
        $this->replyBody           = '';
        unset($this->selectedTrade, $this->threadComments, $this->hasConversation);
    }

    public function addNote(): void
    {
        $this->validate([
            'newNoteBody'  => 'required|string|max:10000',
            'newNotePhase' => ['required', Rule::in(array_column(NotePhase::cases(), 'value'))],
        ]);

        $trade = $this->selectedTrade;
        if (! $trade) return;

        TradeNote::create([
            'trade_id'    => $trade->id,
            'created_by'  => $this->journal->user_id,
            'body'        => trim($this->newNoteBody),
            'phase'       => NotePhase::from($this->newNotePhase),
            'occurred_at' => now(),
        ]);

        $this->newNoteBody = '';
        $this->addingNote  = false;
        unset($this->selectedTrade, $this->trades);
    }

    public function startEditNote(int $id): void
    {
        $note = TradeNote::find($id);
        if (! $note || $note->trade->journal_id !== $this->journal->id) return;
        $this->editingNoteId = $id;
        $this->editNoteBody  = $note->body;
        $this->editNotePhase = $note->phase->value;
    }

    public function saveNote(): void
    {
        $this->validate([
            'editNoteBody'  => 'required|string|max:10000',
            'editNotePhase' => ['required', Rule::in(array_column(NotePhase::cases(), 'value'))],
        ]);

        $note = TradeNote::find($this->editingNoteId);
        if (! $note || $note->trade->journal_id !== $this->journal->id) return;

        $note->update([
            'body'  => trim($this->editNoteBody),
            'phase' => NotePhase::from($this->editNotePhase),
        ]);

        $this->editingNoteId = null;
        $this->editNoteBody  = '';
        unset($this->selectedTrade, $this->trades);
    }

    public function cancelEdit(): void
    {
        $this->editingNoteId = null;
        $this->editNoteBody  = '';
    }

    public function confirmDelete(int $id): void
    {
        $this->confirmDeleteNoteId = $id;
    }

    public function deleteNote(int $id): void
    {
        $note = TradeNote::find($id);
        if (! $note || $note->trade->journal_id !== $this->journal->id) return;
        $note->delete();
        $this->confirmDeleteNoteId = null;
        unset($this->selectedTrade, $this->trades);
    }

    public function uploadScreenshot(): void
    {
        $this->validate([
            'screenshotUpload' => 'required|file|mimes:png,jpeg,jpg|max:10240',
        ]);

        $trade = $this->selectedTrade;
        if (! $trade) return;

        $ext  = $this->screenshotUpload->extension();
        $name = $trade->uuid . '-' . uniqid() . '.' . $ext;

        Storage::disk('local')->putFileAs(
            "trade-screenshots/{$this->journal->id}",
            $this->screenshotUpload,
            $name
        );

        TradeScreenshot::create([
            'trade_id'  => $trade->id,
            'disk'      => 'local',
            'path'      => "trade-screenshots/{$this->journal->id}/{$name}",
            'mime_type' => $this->screenshotUpload->getMimeType(),
            'bytes'     => $this->screenshotUpload->getSize(),
            'source'    => ScreenshotSource::ManualUpload,
        ]);

        $this->screenshotUpload = null;
        unset($this->selectedTrade);
    }

    public function deleteScreenshot(int $id): void
    {
        $shot = TradeScreenshot::whereHas('trade', fn ($q) => $q->where('journal_id', $this->journal->id))
            ->findOrFail($id);

        Storage::disk($shot->disk)->delete($shot->path);
        $shot->delete();
        unset($this->selectedTrade);
    }

    // ── Invite a friend to comment (issue #18) ─────────────────────────────
    // Everything here goes through selectedTrade, which only resolves trades
    // in this journal, and is re-authorized against TradePolicy::invite.

    /** Accepted friends, each with their invitation (if any) to the selected trade. */
    #[Computed]
    public function inviteCandidates(): Collection
    {
        $trade = $this->selectedTrade;
        if (! $trade) {
            return collect();
        }

        $me = auth()->user();
        $invitations = TradeInvitation::where('trade_id', $trade->id)->get()->keyBy('invited_user_id');

        return Friendship::with(['requester', 'recipient'])
            ->involving($me)
            ->accepted()
            ->get()
            ->map(fn (Friendship $f) => $f->otherUser($me))
            ->sortBy(fn ($u) => strtolower($u->name))
            ->map(fn ($u) => ['user' => $u, 'invitation' => $invitations->get($u->id)])
            ->values();
    }

    public function inviteFriend(int $userId): void
    {
        $trade = $this->selectedTrade;
        if (! $trade) return;
        $this->authorize('invite', $trade);

        $me     = auth()->user();
        $friend = User::find($userId);
        if (! $friend || ! $friend->isActive() || ! $me->isFriendsWith($friend)) return;

        $invitation = TradeInvitation::firstOrNew(['trade_id' => $trade->id, 'invited_user_id' => $friend->id]);
        if ($invitation->exists && $invitation->isActive()) return;

        $invitation->fill(['invited_by_user_id' => $me->id, 'status' => InvitationStatus::Pending])->save();

        Mail::to($friend->email)->send(new TradeInvitationMail($invitation->load(['trade', 'invitedUser', 'invitedBy'])));
        unset($this->inviteCandidates, $this->trades, $this->hasConversation);
    }

    public function revokeInvitation(int $invitationId): void
    {
        $trade = $this->selectedTrade ?? abort(404);
        $this->authorize('invite', $trade);

        $invitation = TradeInvitation::where('trade_id', $trade->id)->find($invitationId) ?? abort(404);
        $invitation->update(['status' => InvitationStatus::Revoked]);
        unset($this->inviteCandidates, $this->trades, $this->hasConversation);
    }

    // ── Comment thread (owner) ──────────────────────────────────────────────
    // Same thread friends see on the conversation page. Posting goes through
    // TradeCommentPoster (authorization, nesting rule, rate limit, email).

    /** Top-level comments on the selected trade, oldest first, with replies. */
    #[Computed]
    public function threadComments(): Collection
    {
        $trade = $this->selectedTrade;

        return $trade
            ? $trade->comments()->whereNull('parent_comment_id')->with(['author', 'replies.author'])->oldest()->get()
            : collect();
    }

    /** Show the thread once there is one, or once someone has been invited to start it. */
    #[Computed]
    public function hasConversation(): bool
    {
        $trade = $this->selectedTrade;

        return $trade && ($this->threadComments->isNotEmpty() || $trade->invitations()->active()->exists());
    }

    public function postComment(): void
    {
        $trade = $this->selectedTrade ?? abort(404);

        app(TradeCommentPoster::class)->post($trade, auth()->user(), [
            'body'  => $this->commentBody,
            'image' => $this->commentImage,
        ]);

        $this->commentBody  = '';
        $this->commentImage = null;
        unset($this->threadComments, $this->hasConversation, $this->trades);
    }

    public function startReply(int $commentId): void
    {
        $this->replyToId  = $commentId;
        $this->replyBody  = '';
        $this->replyImage = null;
        $this->resetErrorBag();
    }

    public function cancelReply(): void
    {
        $this->replyToId  = null;
        $this->replyBody  = '';
        $this->replyImage = null;
    }

    public function postReply(): void
    {
        $trade = $this->selectedTrade ?? abort(404);

        app(TradeCommentPoster::class)->post($trade, auth()->user(), [
            'body'              => $this->replyBody,
            'parent_comment_id' => $this->replyToId,
            'image'             => $this->replyImage,
        ]);

        $this->cancelReply();
        unset($this->threadComments, $this->hasConversation, $this->trades);
    }

    public function deleteTrade(string $uuid): void
    {
        $trade = $this->journal->trades()
            ->with('screenshots')
            ->where('uuid', $uuid)
            ->firstOrFail();

        foreach ($trade->screenshots as $shot) {
            Storage::disk($shot->disk)->delete($shot->path);
        }
        foreach ($trade->comments()->whereNotNull('image_path')->get() as $comment) {
            Storage::disk($comment->image_disk)->delete($comment->image_path);
        }

        // Executions, legs, screenshots, notes, invitations and comments all
        // cascadeOnDelete at the DB level — only the files above need explicit cleanup,
        // everything else goes with the trade row.
        $trade->delete();

        $this->selectedUuid  = '';
        $this->editingTrade  = false;
        $this->tradeEditForm = [];
        unset($this->selectedTrade, $this->trades);
    }

    public function holdingDuration(Trade $trade): string
    {
        $s = abs((int) $trade->entry_at->diffInSeconds($trade->exit_at));
        if ($s < 60) return "{$s}s";
        $m = intdiv($s, 60);
        $s = $s % 60;
        if ($m < 60) return $s > 0 ? "{$m}m {$s}s" : "{$m}m";
        $h = intdiv($m, 60);
        $m = $m % 60;
        return $m > 0 ? "{$h}h {$m}m" : "{$h}h";
    }

    private function ptsDisplay(Trade $trade): string
    {
        $pts  = abs((float) $trade->points);
        $sign = (float) $trade->net_pnl >= 0 ? '+' : '-';
        return $sign . number_format($pts, 2) . ' pt';
    }

    private function pnlDisplay(float $value): string
    {
        $sign = $value >= 0 ? '+' : '-';
        return $sign . '$' . number_format(abs($value), 2);
    }

    public function startEditTrade(): void
    {
        $t  = $this->selectedTrade;
        if (!$t) return;
        $tz = $t->account->effectiveTimezone();
        $this->tradeEditForm = [
            'direction'        => $t->direction->value,
            'quantity'         => (string) $t->quantity,
            'entry_price'      => number_format((float) $t->entry_price, 2, '.', ''),
            'exit_price'       => number_format((float) $t->exit_price,  2, '.', ''),
            'entry_at'         => $t->entry_at->setTimezone($tz)->format('Y-m-d\TH:i'),
            'exit_at'          => $t->exit_at->setTimezone($tz)->format('Y-m-d\TH:i'),
            'exit_reason'      => $t->exit_reason->value,
            'trade_type'       => $t->trade_type?->value ?? '',
            'entry_order_name' => $t->entry_order_name ?? '',
            'exit_order_name'  => $t->exit_order_name ?? '',
        ];
        $this->editingTrade = true;
    }

    public function cancelEditTrade(): void
    {
        $this->editingTrade  = false;
        $this->tradeEditForm = [];
    }

    public function saveTrade(): void
    {
        $trade = $this->selectedTrade;
        if (!$trade) return;

        $data       = $this->tradeEditForm;
        $tz         = $trade->account->effectiveTimezone();
        $direction  = Direction::from($data['direction']);
        $entryPrice = round((float) $data['entry_price'], 2);
        $exitPrice  = round((float) $data['exit_price'], 2);
        $quantity   = max(1, (int) $data['quantity']);

        $entryAt = Carbon::createFromFormat('Y-m-d\TH:i', $data['entry_at'], $tz)->setTimezone('UTC');
        $exitAt  = Carbon::createFromFormat('Y-m-d\TH:i', $data['exit_at'],  $tz)->setTimezone('UTC');

        $points   = $direction === Direction::Long ? ($exitPrice - $entryPrice) : ($entryPrice - $exitPrice);
        $ticks    = (float) $trade->tick_size > 0 ? (int) round($points / (float) $trade->tick_size) : 0;
        $grossPnl = round($points * $quantity * (float) $trade->point_value, 2);
        $netPnl   = round($grossPnl - (float) $trade->commission - (float) $trade->fees, 2);

        $trade->update([
            'direction'        => $direction,
            'quantity'         => $quantity,
            'entry_price'      => $entryPrice,
            'exit_price'       => $exitPrice,
            'entry_at'         => $entryAt,
            'exit_at'          => $exitAt,
            'exit_reason'      => ExitReason::from($data['exit_reason']),
            'trade_type'       => $data['trade_type'] ? \App\Enums\TradeType::from($data['trade_type']) : null,
            'entry_order_name' => $data['entry_order_name'] ?? '',
            'exit_order_name'  => $data['exit_order_name'] ?? '',
            'points'           => $points,
            'ticks'            => $ticks,
            'gross_pnl'        => $grossPnl,
            'net_pnl'          => $netPnl,
        ]);

        $this->editingTrade  = false;
        $this->tradeEditForm = [];
        unset($this->selectedTrade);
    }
}; ?>

{{-- ─────────────────────────────────────────────────────────────────── --}}
{{-- Template                                                            --}}
{{-- ─────────────────────────────────────────────────────────────────── --}}
<div class="flex flex-col text-gray-900 dark:text-gray-100" style="height:calc(100vh - 4rem - 1.5rem)">

    {{-- ── Filter bar ── --}}
    <div class="flex flex-wrap items-end gap-x-5 gap-y-3 px-6 py-4 bg-gray-50 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 flex-shrink-0">
        <div class="flex flex-col gap-1">
            <label class="text-xs font-semibold text-gray-600 dark:text-gray-500 uppercase tracking-wider">From</label>
            <input type="date" wire:model.live="dateFrom"
                class="bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100 text-sm rounded px-2 py-1 focus:outline-none focus:ring-1 focus:ring-indigo-500">
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-semibold text-gray-600 dark:text-gray-500 uppercase tracking-wider">To</label>
            <input type="date" wire:model.live="dateTo"
                class="bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100 text-sm rounded px-2 py-1 focus:outline-none focus:ring-1 focus:ring-indigo-500">
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-semibold text-gray-600 dark:text-gray-500 uppercase tracking-wider">Search</label>
            <input type="text" wire:model.live.debounce.300ms="search"
                placeholder="Setup, tag, instrument…"
                class="bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100 text-sm rounded px-3 py-1 w-48 focus:outline-none focus:ring-1 focus:ring-indigo-500 placeholder-gray-400 dark:placeholder-gray-500">
        </div>
        <x-account-filter :accounts="$this->accounts" />
        <div class="flex flex-col gap-1">
            <span class="text-xs opacity-0 leading-none select-none">&nbsp;</span>
            <label class="flex items-center gap-2 cursor-pointer select-none text-sm text-gray-700 dark:text-gray-300 py-1">
                <input type="checkbox" wire:model.live="needsNote" class="rounded border-gray-300 dark:border-gray-600 bg-gray-100 dark:bg-gray-700 text-indigo-500 focus:ring-indigo-500">
                Needs a journal entry
            </label>
        </div>
    </div>

    {{-- ── Summary strip (6 bordered boxes) ── --}}
    @php $s = $this->summary; @endphp
    <div class="flex flex-shrink-0 border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900" style="margin:0.75rem 1.5rem;border-radius:0.5rem;overflow:hidden;flex-shrink:0">
        <div class="flex-1 px-5 py-3 border-r border-gray-200 dark:border-gray-700">
            <div class="text-xs font-semibold text-gray-600 dark:text-gray-500 uppercase tracking-wider">Trades</div>
            <div class="text-2xl font-bold text-gray-900 dark:text-gray-100 mt-0.5">{{ $s['total'] }}</div>
        </div>
        <div class="flex-1 px-5 py-3 border-r border-gray-200 dark:border-gray-700">
            <div class="text-xs font-semibold text-gray-600 dark:text-gray-500 uppercase tracking-wider">Net P&amp;L</div>
            <div class="text-2xl font-bold mt-0.5 {{ $s['net_pnl'] >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                {{ $this->pnlDisplay($s['net_pnl']) }}
            </div>
        </div>
        <div class="flex-1 px-5 py-3 border-r border-gray-200 dark:border-gray-700">
            <div class="text-xs font-semibold text-gray-600 dark:text-gray-500 uppercase tracking-wider">Win Rate</div>
            <div class="text-2xl font-bold text-gray-900 dark:text-gray-100 mt-0.5">{{ $s['win_rate'] }}%</div>
        </div>
        <div class="flex-1 px-5 py-3 border-r border-gray-200 dark:border-gray-700">
            <div class="text-xs font-semibold text-gray-600 dark:text-gray-500 uppercase tracking-wider">Avg Win</div>
            <div class="text-2xl font-bold text-green-600 dark:text-green-400 mt-0.5">+{{ number_format($s['avg_win_pts'], 2) }} pt</div>
        </div>
        <div class="flex-1 px-5 py-3 border-r border-gray-200 dark:border-gray-700">
            <div class="text-xs font-semibold text-gray-600 dark:text-gray-500 uppercase tracking-wider">Avg Loss</div>
            <div class="text-2xl font-bold text-red-600 dark:text-red-400 mt-0.5">-{{ number_format($s['avg_loss_pts'], 2) }} pt</div>
        </div>
        <div class="flex-1 px-5 py-3">
            <div class="text-xs font-semibold text-gray-600 dark:text-gray-500 uppercase tracking-wider">Journaled</div>
            <div class="text-2xl font-bold text-gray-900 dark:text-gray-100 mt-0.5">{{ $s['journaled'] }}/{{ $s['total'] }}</div>
        </div>
    </div>

    {{-- ── Workspace ── --}}
    @php
        $sel = $selectedAccountIds;
        $overviewLabel = ($sel === null || count($sel) === count($this->accounts))
            ? 'All Accounts'
            : (count($sel) === 1
                ? ($this->accounts->firstWhere('id', $sel[0])?->name ?? 'Account')
                : count($sel) . ' accounts');
    @endphp
    <div style="flex:1 1 0;min-height:0;padding:0 1.5rem 1.5rem;display:flex;gap:0.75rem">

        {{-- ─── Left: trade list ─── --}}
        <div class="journal-scroll border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900" style="width:35%;flex-shrink:0;border-radius:0.5rem;overflow-y:auto;overflow-x:hidden">
            {{-- Overview row — always visible, active when no trade is selected --}}
            <button
                wire:click="$set('selectedUuid', '')"
                class="w-full text-left px-4 py-3 border-b border-gray-200 dark:border-gray-700 transition-colors
                    {{ $selectedUuid === ''
                        ? 'bg-indigo-50 dark:bg-indigo-950/40 border-l-[3px] border-l-indigo-500'
                        : 'border-l-[3px] border-l-transparent hover:bg-gray-100 dark:hover:bg-gray-800/50' }}"
            >
                <div class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-gray-500 dark:text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"/>
                    </svg>
                    <span class="text-sm font-semibold {{ $selectedUuid === '' ? 'text-indigo-700 dark:text-indigo-300' : 'text-gray-700 dark:text-gray-300' }}">
                        Overview
                    </span>
                    <span class="text-xs text-gray-600 dark:text-gray-500 ml-1">{{ $overviewLabel }}</span>
                </div>
            </button>

            @if($this->trades->isEmpty())
                <div class="flex flex-col items-center justify-center py-10 text-gray-600 dark:text-gray-500 px-6 text-center">
                    <p class="text-sm">No trades match the current filters.</p>
                </div>
            @else
                @foreach($this->grouped as $date => $dayTrades)
                    @php
                        $dayPnl   = $dayTrades->sum(fn($t) => (float) $t->net_pnl);
                        $dayLabel = strtoupper(\Carbon\Carbon::parse($date)->format('D M j, Y'));
                    @endphp
                    {{-- Date header --}}
                    <div class="sticky top-0 z-10 flex justify-between items-center px-4 py-2 bg-gray-50 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 text-xs font-semibold text-gray-500 dark:text-gray-400 tracking-wide">
                        <span>{{ $dayLabel }}</span>
                        <span class="font-normal {{ $dayPnl >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                            {{ $dayTrades->count() }} trade{{ $dayTrades->count() !== 1 ? 's' : '' }}
                            &middot; {{ $this->pnlDisplay($dayPnl) }}
                        </span>
                    </div>
                    {{-- Trade rows --}}
                    @foreach($dayTrades as $trade)
                        @php
                            $isSelected = $selectedUuid === $trade->uuid;
                            $isLong     = $trade->direction->value === 'long';
                            $netPnl     = (float) $trade->net_pnl;
                            $isWinner   = $netPnl > 0;
                            $localTime  = $trade->entry_at_local->format('g:i A');
                        @endphp
                        <button
                            wire:click="selectTrade('{{ $trade->uuid }}')"
                            class="w-full text-left px-4 py-3 border-b border-gray-100 dark:border-gray-700/40 transition-colors
                                {{ $isSelected
                                    ? 'bg-indigo-50 dark:bg-indigo-950/40 border-l-[3px] border-l-indigo-500'
                                    : 'border-l-[3px] border-l-transparent hover:bg-gray-100 dark:hover:bg-gray-800/50' }}"
                        >
                            <div class="flex items-start gap-3">
                                {{-- Time --}}
                                <div class="text-xs text-gray-600 dark:text-gray-500 w-[3.75rem] flex-shrink-0 pt-0.5 leading-tight">{{ $localTime }}</div>

                                {{-- Direction badge --}}
                                <span class="w-5 h-5 rounded-full flex items-center justify-center text-[11px] font-bold flex-shrink-0 mt-0.5
                                    {{ $isLong ? 'bg-sky-100 dark:bg-sky-800/80 text-sky-700 dark:text-sky-300 border border-sky-300 dark:border-sky-600' : 'bg-rose-100 dark:bg-rose-900/80 text-rose-700 dark:text-rose-300 border border-rose-300 dark:border-rose-700' }}">
                                    {{ $isLong ? 'L' : 'S' }}
                                </span>

                                {{-- Trade info --}}
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-baseline justify-between gap-2">
                                        <div class="text-sm font-semibold text-gray-900 dark:text-gray-100 truncate">
                                            {{ $trade->instrument_symbol }} &times; {{ $trade->quantity }}
                                        </div>
                                        <div class="text-sm font-bold flex-shrink-0 {{ $isWinner ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                            {{ $this->ptsDisplay($trade) }}
                                        </div>
                                    </div>
                                    <div class="flex items-center justify-between gap-2 mt-0.5">
                                        <div class="flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-500 min-w-0">
                                            <span class="truncate">{{ number_format((float)$trade->entry_price, 2) }} &rarr; {{ number_format((float)$trade->exit_price, 2) }}</span>
                                            <span class="inline-block px-1 py-px bg-gray-200 dark:bg-gray-700/80 text-gray-500 dark:text-gray-400 rounded text-[10px] flex-shrink-0">Exit</span>
                                        </div>
                                        <div class="text-xs flex-shrink-0 {{ $isWinner ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                            {{ $this->pnlDisplay($netPnl) }}
                                        </div>
                                    </div>
                                    @if($trade->notes->isNotEmpty() || $trade->comments_count > 0 || $trade->active_invitations_count > 0)
                                        <div class="mt-1 flex items-center gap-3">
                                            @if($trade->notes->isNotEmpty())
                                                <div class="flex items-center gap-1 text-[11px] text-indigo-600 dark:text-indigo-400">
                                                    <svg class="w-3 h-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                        <path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"/>
                                                    </svg>
                                                    Noted
                                                </div>
                                            @endif
                                            @if($trade->comments_count > 0)
                                                <div class="flex items-center gap-1 text-[11px] text-indigo-600 dark:text-indigo-400">
                                                    <svg class="w-3 h-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M18 10c0 3.866-3.582 7-8 7a8.84 8.84 0 01-4.083-.98L2 17l1.338-3.123C2.493 12.767 2 11.434 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7z" clip-rule="evenodd"/>
                                                    </svg>
                                                    {{ $trade->comments_count }} {{ Str::plural('comment', $trade->comments_count) }}
                                                </div>
                                            @elseif($trade->active_invitations_count > 0)
                                                <div class="flex items-center gap-1 text-[11px] text-indigo-600 dark:text-indigo-400">
                                                    <svg class="w-3 h-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                        <path d="M8 9a3 3 0 100-6 3 3 0 000 6zM8 11a6 6 0 016 6H2a6 6 0 016-6zM16 7a1 1 0 10-2 0v1h-1a1 1 0 100 2h1v1a1 1 0 102 0v-1h1a1 1 0 100-2h-1V7z"/>
                                                    </svg>
                                                    Shared
                                                </div>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </button>
                    @endforeach
                @endforeach
            @endif
        </div>

        {{-- ─── Right: detail or overview ─── --}}
        <div class="journal-scroll border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900" style="flex:1 1 0;min-width:0;border-radius:0.5rem;overflow-y:auto;overflow-x:hidden">
            @if($this->selectedTrade)
                @php
                    $t       = $this->selectedTrade;
                    $isLong  = $t->direction->value === 'long';
                    $netPnl  = (float) $t->net_pnl;
                    $isWin   = $netPnl > 0;
                    $localEntry = $t->entry_at_local;
                    $localExit  = $t->exit_at_local;
                @endphp
                <div class="p-6 max-w-3xl" style="margin:0 auto">

                    {{-- Title row --}}
                    <div class="flex items-start justify-between gap-4 mb-1">
                        <h2 class="text-2xl font-bold text-gray-900 dark:text-gray-100">
                            @if($editingTrade)
                                Editing Trade
                            @else
                                {{ $isLong ? 'Long' : 'Short' }} {{ $t->quantity }} {{ $t->instrument }}
                            @endif
                        </h2>
                        <div class="flex items-center gap-2 flex-shrink-0">
                            @if(!$editingTrade)
                                <div class="text-right">
                                    <div class="text-2xl font-bold {{ $isWin ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                        {{ $this->pnlDisplay($netPnl) }}
                                    </div>
                                    <div class="text-sm {{ $isWin ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                        {{ $this->ptsDisplay($t) }}
                                    </div>
                                </div>
                                <button wire:click="$toggle('showInvite')"
                                    title="Invite a friend to comment" aria-label="Invite a friend to comment"
                                    class="text-xs px-3 py-1.5 rounded border transition-colors {{ $showInvite ? 'border-indigo-400 dark:border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-gray-300 dark:border-gray-600 text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:border-gray-300 dark:hover:border-gray-500' }}">
                                    <x-heroicon-o-user-plus class="h-4 w-4" />
                                </button>
                                <button wire:click="startEditTrade"
                                    title="Edit" aria-label="Edit"
                                    class="text-xs px-3 py-1.5 rounded border border-gray-300 dark:border-gray-600 text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:border-gray-300 dark:hover:border-gray-500 transition-colors">
                                    <x-heroicon-o-pencil-square class="h-4 w-4" />
                                </button>
                                <button wire:click="deleteTrade('{{ $t->uuid }}')"
                                    wire:confirm="Delete this trade permanently? All notes, images, and trade data will be deleted. This cannot be undone."
                                    title="Delete" aria-label="Delete"
                                    class="text-xs px-3 py-1.5 rounded border border-gray-300 dark:border-gray-600 text-gray-500 dark:text-gray-400 hover:text-red-600 dark:hover:text-red-400 hover:border-red-300 dark:hover:border-red-500 transition-colors">
                                    <x-heroicon-o-trash class="h-4 w-4" />
                                </button>
                            @else
                                <button wire:click="saveTrade"
                                    class="text-xs px-3 py-1.5 rounded bg-indigo-600 hover:bg-indigo-500 text-white transition-colors">
                                    Save
                                </button>
                                <button wire:click="cancelEditTrade"
                                    class="text-xs px-3 py-1.5 rounded border border-gray-300 dark:border-gray-600 text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 transition-colors">
                                    Cancel
                                </button>
                            @endif
                        </div>
                    </div>
                    <p class="text-xs text-gray-600 dark:text-gray-500 mb-5">
                        {{ $localEntry->format('D M j, Y') }}
                        &middot;
                        {{ $t->account->name }}
                        @if($t->account->connection)
                            &middot; {{ $t->account->connection }}
                        @endif
                        @if($t->source === 'csv_import')
                            &middot; from CSV import
                        @elseif($t->source === 'ninjatrader_8')
                            &middot; from NinjaTrader 8
                        @endif
                    </p>

                    @if($showInvite && !$editingTrade)
                    {{-- Invite a friend to comment --}}
                    <div class="mb-6 rounded-lg border border-gray-200 dark:border-gray-700 px-4 py-3">
                        <div class="flex items-center justify-between gap-3 mb-2">
                            <h3 class="text-xs font-semibold text-gray-600 dark:text-gray-500 uppercase tracking-wider">Invite a friend to comment</h3>
                            <a href="{{ route('trades.shared', $t) }}" target="_blank" rel="noopener"
                                class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline">Open shared page ↗</a>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">
                            They'll see this trade's screenshot, prices, P&amp;L and notes on a separate page — never your account or other trades.
                        </p>
                        @forelse($this->inviteCandidates as $c)
                            @php $inv = $c['invitation']; @endphp
                            <div class="flex items-center justify-between gap-3 py-1.5 border-t border-gray-100 dark:border-gray-800 first:border-t-0" wire:key="inv-{{ $c['user']->id }}">
                                <span class="text-sm text-gray-800 dark:text-gray-200">{{ $c['user']->name }}</span>
                                <span class="flex items-center gap-2">
                                    @if($inv?->isActive())
                                        <span class="text-xs {{ $inv->isAccepted() ? 'text-green-600 dark:text-green-400' : 'text-gray-500 dark:text-gray-400' }}">{{ $inv->status->label() }}</span>
                                        <button wire:click="revokeInvitation({{ $inv->id }})"
                                            wire:confirm="Revoke {{ $c['user']->name }}'s access to this trade?"
                                            class="text-xs px-2 py-1 rounded border border-gray-300 dark:border-gray-600 text-gray-500 dark:text-gray-400 hover:text-red-600 dark:hover:text-red-400 transition-colors">Revoke</button>
                                    @else
                                        @if($inv)
                                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $inv->status->label() }}</span>
                                        @endif
                                        <button wire:click="inviteFriend({{ $c['user']->id }})"
                                            class="text-xs px-2 py-1 rounded bg-indigo-600 hover:bg-indigo-500 text-white transition-colors">{{ $inv ? 'Invite again' : 'Invite' }}</button>
                                    @endif
                                </span>
                            </div>
                        @empty
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                No friends yet — add some on the <a href="{{ route('friends.index') }}" wire:navigate class="underline">Friends</a> page.
                            </p>
                        @endforelse
                    </div>
                    @endif

                    @if(!$editingTrade)
                    {{-- Timeline (view mode) --}}
                    <div class="flex items-center gap-4 mb-6 bg-gray-50 dark:bg-gray-800 rounded-lg px-5 py-4">
                        <div class="flex-shrink-0">
                            <div class="text-xs text-gray-600 dark:text-gray-500 uppercase tracking-wide mb-1">Entry Filled</div>
                            <div class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ $localEntry->format('g:i:s A') }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ number_format((float)$t->entry_price, 2) }}</div>
                        </div>
                        <div class="flex flex-col items-center flex-1 gap-1">
                            <span class="text-xs text-gray-600 dark:text-gray-500">held {{ $this->holdingDuration($t) }}</span>
                            <div class="flex items-center w-full">
                                <div class="w-2 h-2 rounded-full border-2 border-gray-300 dark:border-gray-500"></div>
                                <div class="flex-1 h-px bg-gray-300 dark:bg-gray-600"></div>
                                <div class="w-2 h-2 rounded-full border-2 border-gray-300 dark:border-gray-500"></div>
                            </div>
                        </div>
                        <div class="flex-shrink-0 text-right">
                            <div class="text-xs text-gray-600 dark:text-gray-500 uppercase tracking-wide mb-1">Exit Filled</div>
                            <div class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ $localExit->format('g:i:s A') }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ number_format((float)$t->exit_price, 2) }}</div>
                        </div>
                    </div>
                    @else
                    {{-- Edit form --}}
                    <div class="mb-6 bg-gray-50 dark:bg-gray-800 rounded-lg p-4 space-y-4">
                        {{-- Row 1: Direction + Quantity --}}
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs text-gray-600 dark:text-gray-500 uppercase tracking-wide mb-1">Direction</label>
                                <select wire:model="tradeEditForm.direction"
                                    class="w-full bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100 text-sm rounded px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                                    <option value="long">Long</option>
                                    <option value="short">Short</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-600 dark:text-gray-500 uppercase tracking-wide mb-1">Quantity</label>
                                <input type="number" min="1" wire:model="tradeEditForm.quantity"
                                    class="w-full bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100 text-sm rounded px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                            </div>
                        </div>
                        {{-- Row 2: Entry / Exit Price --}}
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs text-gray-600 dark:text-gray-500 uppercase tracking-wide mb-1">Entry Price</label>
                                <input type="number" step="0.01" wire:model="tradeEditForm.entry_price"
                                    class="w-full bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100 text-sm rounded px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                            </div>
                            <div>
                                <label class="block text-xs text-gray-600 dark:text-gray-500 uppercase tracking-wide mb-1">Exit Price</label>
                                <input type="number" step="0.01" wire:model="tradeEditForm.exit_price"
                                    class="w-full bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100 text-sm rounded px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                            </div>
                        </div>
                        {{-- Row 3: Entry / Exit Time --}}
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs text-gray-600 dark:text-gray-500 uppercase tracking-wide mb-1">Entry Time</label>
                                <input type="datetime-local" wire:model="tradeEditForm.entry_at"
                                    class="w-full bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100 text-sm rounded px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                            </div>
                            <div>
                                <label class="block text-xs text-gray-600 dark:text-gray-500 uppercase tracking-wide mb-1">Exit Time</label>
                                <input type="datetime-local" wire:model="tradeEditForm.exit_at"
                                    class="w-full bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100 text-sm rounded px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                            </div>
                        </div>
                        {{-- Row 4: Trade Type + Exit Reason --}}
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs text-gray-600 dark:text-gray-500 uppercase tracking-wide mb-1">Trade Type</label>
                                <select wire:model="tradeEditForm.trade_type"
                                    class="w-full bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100 text-sm rounded px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                                    <option value="">— unknown —</option>
                                    <option value="2ES">Second Entry Short</option>
                                    <option value="2EL">Second Entry Long</option>
                                    <option value="RS">Range Short</option>
                                    <option value="RL">Range Long</option>
                                    <option value="F2ES">Failed Second Entry Short</option>
                                    <option value="F2EL">Failed Second Entry Long</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-600 dark:text-gray-500 uppercase tracking-wide mb-1">Exit Reason</label>
                                <select wire:model="tradeEditForm.exit_reason"
                                    class="w-full bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100 text-sm rounded px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                                    <option value="stop">Stop</option>
                                    <option value="profit_target">Profit Target</option>
                                    <option value="exit">Exit</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>
                        {{-- Row 5: Entry / Exit Order --}}
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs text-gray-600 dark:text-gray-500 uppercase tracking-wide mb-1">Entry Order</label>
                                <input type="text" wire:model="tradeEditForm.entry_order_name" placeholder="—"
                                    class="w-full bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100 text-sm rounded px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                            </div>
                            <div>
                                <label class="block text-xs text-gray-600 dark:text-gray-500 uppercase tracking-wide mb-1">Exit Order</label>
                                <input type="text" wire:model="tradeEditForm.exit_order_name" placeholder="—"
                                    class="w-full bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100 text-sm rounded px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                            </div>
                        </div>
                        <p class="text-xs text-gray-600 dark:text-gray-500">Points, ticks, gross, and net P&amp;L are recalculated automatically on save.</p>
                    </div>
                    @endif

                    {{-- Metric grid (view mode) --}}
                    @if(!$editingTrade)
                    <div class="grid grid-cols-4 gap-px bg-gray-100 dark:bg-gray-700 rounded-lg overflow-hidden mb-6 text-sm">
                        @foreach([
                            ['ENTRY PRICE',  number_format((float)$t->entry_price, 2)],
                            ['EXIT PRICE',   number_format((float)$t->exit_price, 2)],
                            ['POINTS',       $this->ptsDisplay($t)],
                            ['TICKS',        ($t->ticks >= 0 ? '+' : '') . $t->ticks],
                            ['GROSS',        $this->pnlDisplay((float)$t->gross_pnl)],
                            ['COMMISSION',   '-$' . number_format((float)$t->commission, 2)],
                            ['NET',          $this->pnlDisplay($netPnl)],
                            ['EXIT BY',      $t->exit_reason->label()],
                            ['ENTRY ORDER',  $t->entry_order_name ?: '—'],
                            ['EXIT ORDER',   $t->exit_order_name  ?: '—'],
                            ['ACCOUNT',      $t->account->name],
                            ['TRADE TYPE',   $t->trade_type?->label() ?: '—'],
                        ] as [$label, $value])
                            <div class="bg-gray-50 dark:bg-gray-800 px-3 py-2.5">
                                <div class="text-xs text-gray-600 dark:text-gray-500 uppercase tracking-wide mb-0.5">{{ $label }}</div>
                                <div class="text-gray-900 dark:text-gray-100 font-medium">{{ $value }}</div>
                            </div>
                        @endforeach
                    </div>
                    @endif

                    {{-- Executions table --}}
                    @if($t->executions->isNotEmpty())
                    <div class="mb-6">
                        <h3 class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2">Executions</h3>
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg overflow-hidden">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 dark:border-gray-700 text-xs text-gray-600 dark:text-gray-500 uppercase">
                                        <th class="px-3 py-2 text-left">Time</th>
                                        <th class="px-3 py-2 text-left">Action</th>
                                        <th class="px-3 py-2 text-right">Qty</th>
                                        <th class="px-3 py-2 text-right">Price</th>
                                        <th class="px-3 py-2 text-left">Order</th>
                                        <th class="px-3 py-2 text-right">Comm.</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($t->executions as $exec)
                                    <tr class="border-b border-gray-100 dark:border-gray-700/50 last:border-0">
                                        <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ $exec->occurred_at->setTimezone($t->account->effectiveTimezone())->format('g:i:s A') }}</td>
                                        <td class="px-3 py-2">
                                            <span class="{{ $exec->role->value === 'entry' ? 'text-sky-600 dark:text-sky-400' : 'text-orange-600 dark:text-orange-400' }}">
                                                {{ ucfirst($exec->action->value) }}
                                                <span class="text-gray-600 dark:text-gray-500">({{ $exec->role->value }})</span>
                                            </span>
                                        </td>
                                        <td class="px-3 py-2 text-right text-gray-700 dark:text-gray-300">{{ $exec->quantity }}</td>
                                        <td class="px-3 py-2 text-right text-gray-700 dark:text-gray-300">{{ number_format((float)$exec->price, 2) }}</td>
                                        <td class="px-3 py-2 text-gray-500 dark:text-gray-400 text-xs">{{ $exec->order_name ?: '—' }}</td>
                                        <td class="px-3 py-2 text-right text-gray-500 dark:text-gray-400">${{ number_format((float)$exec->commission, 2) }}</td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                    @endif

                    {{-- Screenshots --}}
                    <div class="mb-6">
                        <h3 class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2">Chart</h3>

                        @if($t->screenshots->isNotEmpty())
                            <div class="space-y-3">
                                @foreach($t->screenshots as $shot)
                                    <div style="position:relative;border-radius:0.5rem;overflow:hidden" class="bg-gray-50 dark:bg-gray-800"
                                        x-data="{ hover: false }" @mouseenter="hover=true" @mouseleave="hover=false">
                                        <img
                                            src="{{ route('journal.screenshot', [$t, $shot]) }}"
                                            alt="Trade chart"
                                            class="w-full h-auto"
                                        >
                                        <button
                                            x-show="hover"
                                            type="button"
                                            onclick="document.getElementById('chart-image-{{ $shot->id }}').showModal()"
                                            title="Expand image" aria-label="Expand image"
                                            class="text-gray-500 dark:text-gray-400 hover:text-gray-100" style="position:absolute;top:0.5rem;right:2.75rem;padding:0.375rem;border-radius:0.25rem;background:rgba(0,0,0,0.65);border:none;cursor:pointer;line-height:0"
                                        >
                                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/>
                                            </svg>
                                        </button>
                                        <x-image-dialog :id="'chart-image-'.$shot->id" :url="route('journal.screenshot', [$t, $shot])" alt="Trade chart" />
                                        <button
                                            x-show="hover"
                                            wire:click="deleteScreenshot({{ $shot->id }})"
                                            wire:confirm="Delete this image?"
                                            title="Delete image" aria-label="Delete image"
                                            class="text-gray-500 dark:text-gray-400 hover:text-red-600 dark:hover:text-red-400" style="position:absolute;top:0.5rem;right:0.5rem;padding:0.375rem;border-radius:0.25rem;background:rgba(0,0,0,0.65);border:none;cursor:pointer;line-height:0"
                                        >
                                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                            </svg>
                                        </button>
                                        @if($shot->caption)
                                            <div class="px-3 py-2 text-xs text-gray-500 dark:text-gray-400">{{ $shot->caption }}</div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        {{-- Upload area --}}
                        <div
                            x-data="{
                                dragging: false,
                                handleDrop(e) {
                                    this.dragging = false;
                                    const files = e.dataTransfer?.files;
                                    if (files?.length) {
                                        $refs.shotInput.files = files;
                                        $refs.shotInput.dispatchEvent(new Event('change', { bubbles: true }));
                                    }
                                }
                            }"
                            @dragover.prevent="dragging = true"
                            @dragleave.prevent="dragging = false"
                            @drop.prevent="handleDrop($event)"
                            @paste.window="
                                const items = $event.clipboardData?.items;
                                if (items) for (const item of Array.from(items)) {
                                    if (item.type.startsWith('image/')) {
                                        $wire.upload('screenshotUpload', item.getAsFile());
                                        break;
                                    }
                                }
                            "
                            @click="$refs.shotInput.click()"
                            :class="dragging && 'border-indigo-500 bg-indigo-500/10'"
                            class="mt-3 border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg p-5 text-center cursor-pointer hover:border-gray-300 dark:hover:border-gray-500 transition-colors"
                        >
                            <input
                                type="file"
                                wire:model="screenshotUpload"
                                accept="image/png,image/jpeg"
                                class="hidden"
                                x-ref="shotInput"
                            >
                            <p class="text-sm text-gray-600 dark:text-gray-500">
                                Drop, paste
                                <kbd class="px-1 py-0.5 text-xs bg-gray-100 dark:bg-gray-700 rounded border border-gray-300 dark:border-gray-600">Ctrl+V</kbd>
                                or click to add a screenshot
                            </p>
                            @error('screenshotUpload')
                                <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        @if($screenshotUpload)
                            <div class="mt-2 flex items-center justify-between bg-gray-50 dark:bg-gray-800 rounded px-3 py-2">
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $screenshotUpload->getClientOriginalName() }}</span>
                                <button wire:click="uploadScreenshot"
                                    class="text-xs bg-indigo-600 hover:bg-indigo-500 text-white px-3 py-1 rounded transition-colors">
                                    Upload
                                </button>
                            </div>
                        @endif
                    </div>

                    {{-- Notes --}}
                    <div class="mb-6">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Notes</h3>
                            @if(! $addingNote)
                                <button wire:click="$set('addingNote', true)"
                                    class="text-xs text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 flex items-center gap-1 transition-colors">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                                    </svg>
                                    Add note
                                </button>
                            @endif
                        </div>

                        {{-- Add note form --}}
                        @if($addingNote)
                            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 mb-4 border border-gray-200 dark:border-gray-700">
                                <div class="mb-3">
                                    <select wire:model="newNotePhase"
                                        class="w-full bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100 text-xs rounded px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-indigo-500 mb-2">
                                        @foreach(\App\Enums\NotePhase::cases() as $phase)
                                            <option value="{{ $phase->value }}">{{ $phase->label() }}</option>
                                        @endforeach
                                    </select>
                                    <textarea wire:model="newNoteBody"
                                        rows="4"
                                        placeholder="Write your note…"
                                        class="w-full bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100 text-sm rounded px-3 py-2 resize-none focus:outline-none focus:ring-1 focus:ring-indigo-500 placeholder-gray-400 dark:placeholder-gray-500"></textarea>
                                    @error('newNoteBody')
                                        <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                                    @enderror
                                </div>
                                <div class="flex gap-2">
                                    <button wire:click="addNote"
                                        class="text-xs bg-indigo-600 hover:bg-indigo-500 text-white px-3 py-1.5 rounded transition-colors">
                                        Save
                                    </button>
                                    <button wire:click="$set('addingNote', false)"
                                        class="text-xs text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 px-3 py-1.5 rounded transition-colors">
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        @endif

                        {{-- Note list --}}
                        @forelse($t->notes as $note)
                            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 mb-3 border border-gray-200 dark:border-gray-700">
                                <div class="flex items-start justify-between gap-2 mb-2">
                                    <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                                        {{ $note->phase->label() }}
                                    </span>
                                    <div class="flex items-center gap-2 flex-shrink-0">
                                        @if($confirmDeleteNoteId === $note->id)
                                            <span class="text-xs text-gray-500 dark:text-gray-400">Delete?</span>
                                            <button wire:click="deleteNote({{ $note->id }})"
                                                class="text-xs text-red-600 dark:text-red-400 hover:text-red-700 dark:hover:text-red-300 transition-colors">Yes</button>
                                            <button wire:click="$set('confirmDeleteNoteId', null)"
                                                class="text-xs text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 transition-colors">No</button>
                                        @else
                                            <button wire:click="startEditNote({{ $note->id }})"
                                                class="text-xs text-gray-600 dark:text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 transition-colors">Edit</button>
                                            <button wire:click="confirmDelete({{ $note->id }})"
                                                class="text-xs text-gray-600 dark:text-gray-500 hover:text-red-600 dark:hover:text-red-400 transition-colors">Delete</button>
                                        @endif
                                    </div>
                                </div>

                                @if($editingNoteId === $note->id)
                                    <div>
                                        <select wire:model="editNotePhase"
                                            class="w-full bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100 text-xs rounded px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-indigo-500 mb-2">
                                            @foreach(\App\Enums\NotePhase::cases() as $phase)
                                                <option value="{{ $phase->value }}">{{ $phase->label() }}</option>
                                            @endforeach
                                        </select>
                                        <textarea wire:model="editNoteBody"
                                            rows="4"
                                            class="w-full bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100 text-sm rounded px-3 py-2 resize-none focus:outline-none focus:ring-1 focus:ring-indigo-500"></textarea>
                                        @error('editNoteBody')
                                            <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                                        @enderror
                                        <div class="flex gap-2 mt-2">
                                            <button wire:click="saveNote"
                                                class="text-xs bg-indigo-600 hover:bg-indigo-500 text-white px-3 py-1.5 rounded transition-colors">
                                                Save
                                            </button>
                                            <button wire:click="cancelEdit"
                                                class="text-xs text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 px-3 py-1.5 rounded transition-colors">
                                                Cancel
                                            </button>
                                        </div>
                                    </div>
                                @else
                                    <p class="text-sm text-gray-800 dark:text-gray-200 whitespace-pre-wrap">{{ $note->body }}</p>
                                    <p class="text-xs text-gray-600 dark:text-gray-500 mt-2">{{ $note->occurred_at->format('M j, Y g:i A') }}</p>
                                @endif
                            </div>
                        @empty
                            <p class="text-sm text-gray-600 dark:text-gray-500 italic">No notes yet.</p>
                        @endforelse
                    </div>

                    {{-- Conversation with invited friends (the same thread as the shared page) --}}
                    @if($this->hasConversation)
                    <div class="mb-6" id="conversation">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Conversation</h3>
                            <a href="{{ route('trades.shared', $t) }}" target="_blank" rel="noopener"
                                class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline">Open conversation page ↗</a>
                        </div>

                        @error('parent_comment_id')
                            <p class="text-xs text-red-600 dark:text-red-400 mb-2">{{ $message }}</p>
                        @enderror

                        <div class="space-y-4">
                            @forelse($this->threadComments as $c)
                                <div wire:key="tc-{{ $c->id }}" id="comment-{{ $c->id }}">
                                    <div class="text-sm"><span class="font-semibold text-gray-900 dark:text-gray-100">{{ $c->author->name }}</span> <span class="text-xs text-gray-500 dark:text-gray-400">{{ $c->created_at->diffForHumans() }}</span></div>
                                    <p class="text-sm text-gray-800 dark:text-gray-200 whitespace-pre-wrap mt-0.5">{{ $c->body }}</p>
                                    @if($c->hasImage())
                                        <x-comment-image :id="$c->id" :url="route('trades.shared.comment-image', [$t, $c])" />
                                    @endif

                                    @if($c->replies->isNotEmpty())
                                        <div class="mt-3 ml-4 pl-4 border-l-2 border-gray-200 dark:border-gray-700 space-y-3">
                                            @foreach($c->replies as $r)
                                                <div wire:key="tc-{{ $r->id }}" id="comment-{{ $r->id }}">
                                                    <div class="text-sm"><span class="font-semibold text-gray-900 dark:text-gray-100">{{ $r->author->name }}</span> <span class="text-xs text-gray-500 dark:text-gray-400">{{ $r->created_at->diffForHumans() }}</span></div>
                                                    <p class="text-sm text-gray-800 dark:text-gray-200 whitespace-pre-wrap mt-0.5">{{ $r->body }}</p>
                                                    @if($r->hasImage())
                                                        <x-comment-image :id="$r->id" :url="route('trades.shared.comment-image', [$t, $r])" />
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif

                                    {{-- Replies go one level deep: only top-level comments get a reply box. --}}
                                    @if($replyToId === $c->id)
                                        <div class="mt-2 ml-4 space-y-2">
                                            <textarea wire:model="replyBody" rows="2" maxlength="5000" placeholder="Reply to {{ $c->author->name }}…"
                                                class="w-full bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100 text-sm rounded px-3 py-2 focus:outline-none focus:ring-1 focus:ring-indigo-500"></textarea>
                                            <label class="block text-xs text-gray-600 dark:text-gray-400">Attach an image (optional, PNG/JPEG up to 10 MB)
                                                <input type="file" wire:model="replyImage" accept="image/png,image/jpeg" class="block mt-1 text-xs text-gray-700 dark:text-gray-300">
                                            </label>
                                            @error('image') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                            @error('body') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                            <div class="flex gap-2">
                                                <button wire:click="postReply" class="text-xs px-3 py-1.5 rounded bg-indigo-600 hover:bg-indigo-500 text-white transition-colors">Post reply</button>
                                                <button wire:click="cancelReply" class="text-xs px-3 py-1.5 rounded border border-gray-300 dark:border-gray-600 text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 transition-colors">Cancel</button>
                                            </div>
                                        </div>
                                    @else
                                        <button wire:click="startReply({{ $c->id }})" class="mt-1 ml-4 text-xs text-indigo-600 dark:text-indigo-400 hover:underline">Reply</button>
                                    @endif
                                </div>
                            @empty
                                <p class="text-sm text-gray-600 dark:text-gray-500 italic">No comments yet — start the conversation.</p>
                            @endforelse
                        </div>

                        <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700 space-y-2">
                            <textarea wire:model="commentBody" rows="2" maxlength="5000" placeholder="Add a comment for your invited friends…"
                                class="w-full bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100 text-sm rounded px-3 py-2 focus:outline-none focus:ring-1 focus:ring-indigo-500"></textarea>
                            <label class="block text-xs text-gray-600 dark:text-gray-400">Attach an image (optional, PNG/JPEG up to 10 MB)
                                <input type="file" wire:model="commentImage" accept="image/png,image/jpeg" class="block mt-1 text-xs text-gray-700 dark:text-gray-300">
                            </label>
                            @if($replyToId === null)
                                @error('image') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                            @endif
                            @if($replyToId === null)
                                @error('body') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                            @endif
                            <button wire:click="postComment" class="text-xs px-3 py-1.5 rounded bg-indigo-600 hover:bg-indigo-500 text-white transition-colors">Post comment</button>
                        </div>
                    </div>
                    @endif

                </div>

            @else
                {{-- ─── No-selection panel ─── --}}
                @if($this->trades->isEmpty())
                    <div class="flex flex-col items-center justify-center h-full px-8 text-center">
                        <svg class="w-14 h-14 text-gray-300 dark:text-gray-700 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"/>
                        </svg>
                        <h3 class="text-lg font-semibold text-gray-700 dark:text-gray-300 mb-2">No trades yet</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-500 mb-5 max-w-xs">
                            Import a NinjaTrader export CSV or configure the NT8 AddOn to start filling your journal.
                        </p>
                        <a href="{{ route('journal.settings.edit') }}" wire:navigate
                            class="text-sm bg-indigo-600 hover:bg-indigo-500 text-white px-4 py-2 rounded transition-colors">
                            Configure intake
                        </a>
                    </div>
                @else
                    {{-- Stats overview when trades exist but none selected --}}
                    <div class="p-6 max-w-2xl" style="margin:0 auto">
                        <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-4">
                            Overview &mdash; {{ $overviewLabel }}
                        </h3>

                        <div class="grid grid-cols-2 gap-4 mb-6">
                            @php
                                $trades = $this->trades;
                                $winners = $trades->filter(fn($t) => (float)$t->net_pnl > 0);
                                $losers  = $trades->filter(fn($t) => (float)$t->net_pnl < 0);
                                $grossWin  = $winners->sum(fn($t) => (float)$t->gross_pnl);
                                $grossLoss = abs($losers->sum(fn($t) => (float)$t->gross_pnl));
                                $pf = $grossLoss > 0 ? round($grossWin / $grossLoss, 2) : null;
                                $expectancy = $trades->count() > 0 ? (float)$trades->sum('net_pnl') / $trades->count() : 0;
                            @endphp
                            @foreach([
                                ['Win / Loss', $winners->count() . 'W / ' . $losers->count() . 'L', 'text-gray-900 dark:text-gray-100'],
                                ['Profit Factor', $pf !== null ? $pf : '—', $pf >= 1 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'],
                                ['Avg Winner', $winners->count() > 0 ? $this->pnlDisplay((float)$winners->avg('net_pnl')) : '—', 'text-green-600 dark:text-green-400'],
                                ['Avg Loser',  $losers->count()  > 0 ? $this->pnlDisplay((float)$losers->avg('net_pnl'))  : '—', 'text-red-600 dark:text-red-400'],
                                ['Expectancy', $this->pnlDisplay($expectancy) . '/trade', $expectancy >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'],
                                ['Journaled', $s['journaled'] . ' / ' . $s['total'] . ' trades', $s['journaled'] === $s['total'] ? 'text-green-600 dark:text-green-400' : 'text-yellow-600 dark:text-yellow-400'],
                            ] as [$label, $value, $color])
                                <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
                                    <div class="text-xs text-gray-600 dark:text-gray-500 uppercase tracking-wide mb-1">{{ $label }}</div>
                                    <div class="text-xl font-bold {{ $color }}">{{ $value }}</div>
                                </div>
                            @endforeach
                        </div>

                        @if($s['journaled'] < $s['total'])
                            <div class="bg-yellow-50 dark:bg-yellow-900/30 border border-yellow-200 dark:border-yellow-700/50 rounded-lg p-4 flex items-start gap-3">
                                <svg class="w-5 h-5 text-yellow-600 dark:text-yellow-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                </svg>
                                <div>
                                    <p class="text-sm font-medium text-yellow-800 dark:text-yellow-300">
                                        {{ $s['total'] - $s['journaled'] }} trade{{ ($s['total'] - $s['journaled']) !== 1 ? 's' : '' }} need a journal entry
                                    </p>
                                    <button wire:click="$set('needsNote', true)"
                                        class="mt-1 text-xs text-yellow-600 dark:text-yellow-400 hover:text-yellow-800 dark:hover:text-yellow-300 underline transition-colors">
                                        Filter to unreviewed trades
                                    </button>
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
            @endif
        </div>{{-- end right panel --}}

    </div>{{-- end workspace padded wrapper --}}
</div>
