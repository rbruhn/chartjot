<?php

use App\Enums\FriendshipStatus;
use App\Enums\InvitationStatus;
use App\Models\Friendship;
use App\Models\Journal;
use App\Models\TradeInvitation;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component {
    public string $searchEmail = '';

    public string $flash = '';

    private function me(): User
    {
        return Auth::user();
    }

    /**
     * Exact, case-insensitive email match only — not a name or partial
     * search — so any approved user can't browse the user directory. Only
     * active users are findable, and never yourself.
     */
    #[Computed]
    public function searchResult(): ?User
    {
        $email = strtolower(trim($this->searchEmail));
        if ($email === '' || ! str_contains($email, '@')) {
            return null;
        }

        return User::whereRaw('lower(email) = ?', [$email])
            ->whereKeyNot($this->me()->id)
            ->get()
            ->first(fn (User $u) => $u->isActive());
    }

    /** Friendship status with the search result, if any, for the button label. */
    #[Computed]
    public function searchFriendship(): ?Friendship
    {
        return $this->searchResult
            ? Friendship::between($this->me(), $this->searchResult)->first()
            : null;
    }

    #[Computed]
    public function incoming(): Collection
    {
        return Friendship::with('requester')
            ->where('recipient_id', $this->me()->id)
            ->where('status', FriendshipStatus::Pending)
            ->latest()
            ->get();
    }

    #[Computed]
    public function outgoing(): Collection
    {
        return Friendship::with('recipient')
            ->where('requester_id', $this->me()->id)
            ->where('status', FriendshipStatus::Pending)
            ->latest()
            ->get();
    }

    #[Computed]
    public function friends(): Collection
    {
        return Friendship::with(['requester', 'recipient'])
            ->involving($this->me())
            ->accepted()
            ->get()
            ->sortBy(fn (Friendship $f) => strtolower($f->otherUser($this->me())->name))
            ->values();
    }

    /** Trade invitations addressed to me that are still live. */
    #[Computed]
    public function invitations(): Collection
    {
        return TradeInvitation::with(['trade', 'invitedBy'])
            ->where('invited_user_id', $this->me()->id)
            ->active()
            ->latest()
            ->get();
    }

    public function acceptInvitation(int $invitationId): void
    {
        $this->pendingInvitationForMe($invitationId)->update(['status' => InvitationStatus::Accepted]);
        $this->resetComputed();
    }

    public function declineInvitation(int $invitationId): void
    {
        $this->pendingInvitationForMe($invitationId)->update(['status' => InvitationStatus::Declined]);
        $this->resetComputed();
    }

    private function pendingInvitationForMe(int $invitationId): TradeInvitation
    {
        return TradeInvitation::where('invited_user_id', $this->me()->id)
            ->where('status', InvitationStatus::Pending)
            ->find($invitationId) ?? abort(404);
    }

    public function sendRequest(int $userId): void
    {
        $me     = $this->me();
        $target = User::whereKeyNot($me->id)->find($userId);

        if (! $target || ! $target->isActive()) {
            return;
        }

        try {
            $existing = Friendship::between($me, $target)->first();

            if (! $existing) {
                Friendship::create([
                    'requester_id' => $me->id,
                    'recipient_id' => $target->id,
                    'status'       => FriendshipStatus::Pending,
                ]);
                $this->flash = "Friend request sent to {$target->name}.";
            } elseif ($existing->isPending() && (int) $existing->recipient_id === $me->id) {
                // They already asked you: sending one back is accepting theirs.
                $existing->update(['status' => FriendshipStatus::Accepted]);
                $this->flash = "You and {$target->name} are now friends.";
            } elseif ($existing->status === FriendshipStatus::Declined) {
                // Re-requesting after a decline reuses the row (the pair is unique).
                $existing->update([
                    'requester_id' => $me->id,
                    'recipient_id' => $target->id,
                    'status'       => FriendshipStatus::Pending,
                ]);
                $this->flash = "Friend request sent to {$target->name}.";
            }
        } catch (UniqueConstraintViolationException) {
            // A concurrent request for the same pair won the race; the row
            // that exists now is the one to show.
        }

        $this->searchEmail = '';
        $this->resetComputed();
    }

    public function accept(int $friendshipId): void
    {
        $this->pendingForMe($friendshipId)->update(['status' => FriendshipStatus::Accepted]);
        $this->resetComputed();
    }

    public function decline(int $friendshipId): void
    {
        $this->pendingForMe($friendshipId)->update(['status' => FriendshipStatus::Declined]);
        $this->resetComputed();
    }

    public function cancel(int $friendshipId): void
    {
        Friendship::where('requester_id', $this->me()->id)
            ->where('status', FriendshipStatus::Pending)
            ->find($friendshipId)?->delete() ?? abort(404);
        $this->resetComputed();
    }

    public function remove(int $friendshipId): void
    {
        $me         = $this->me();
        $friendship = Friendship::involving($me)->accepted()->find($friendshipId) ?? abort(404);
        $other      = $friendship->otherUser($me);

        // Ending the friendship also ends any live invitations between the
        // two, in both directions (the access policy requires friendship
        // too, but the invitation rows should say what's actually true).
        $pairs = [
            [$me->id, Journal::where('user_id', $other->id)->value('id')],
            [$other->id, Journal::where('user_id', $me->id)->value('id')],
        ];
        foreach ($pairs as [$invitee, $ownerJournalId]) {
            TradeInvitation::where('invited_user_id', $invitee)
                ->whereIn('trade_id', fn ($q) => $q->select('id')->from('trades')->where('journal_id', $ownerJournalId))
                ->active()
                ->update(['status' => InvitationStatus::Revoked]);
        }

        $friendship->delete();
        $this->resetComputed();
    }

    /** A pending request addressed to the current user — 404 for anything else. */
    private function pendingForMe(int $friendshipId): Friendship
    {
        return Friendship::where('recipient_id', $this->me()->id)
            ->where('status', FriendshipStatus::Pending)
            ->find($friendshipId) ?? abort(404);
    }

    private function resetComputed(): void
    {
        unset($this->searchResult, $this->searchFriendship, $this->incoming, $this->outgoing, $this->friends, $this->invitations);
    }
}; ?>

@php
    $card   = 'rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900';
    $h3     = 'text-base font-semibold text-gray-900 dark:text-gray-100';
    $row    = 'flex items-center justify-between gap-4 py-3 border-t border-gray-100 dark:border-gray-800 first:border-t-0';
    $btn    = 'text-xs px-3 py-1.5 rounded border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-gray-100 transition-colors';
    $btnPri = 'text-xs px-3 py-1.5 rounded bg-indigo-600 hover:bg-indigo-500 text-white transition-colors';
    $muted  = 'text-sm text-gray-500 dark:text-gray-400';
@endphp

<div class="py-8">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

        <div>
            <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100">Friends</h2>
            <p class="mt-1 {{ $muted }}">
                Friends can be invited to view and comment on a specific trade. Being friends doesn't share anything by itself.
            </p>
        </div>

        @if ($flash)
            <div class="rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/30 dark:text-green-300">{{ $flash }}</div>
        @endif

        {{-- ── Add a friend ── --}}
        <section class="{{ $card }} p-6">
            <h3 class="{{ $h3 }} mb-1">Add a friend</h3>
            <p class="{{ $muted }} mb-3">Enter their exact Chart Jot email address.</p>
            <input type="email" wire:model.live.debounce.400ms="searchEmail" placeholder="friend@example.com" autocomplete="off"
                class="w-full sm:w-80 bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100 text-sm rounded px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-indigo-500">

            @if ($this->searchResult)
                @php $f = $this->searchFriendship; $u = $this->searchResult; @endphp
                <div class="{{ $row }} mt-3">
                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $u->name }}</span>
                    @if ($f?->isAccepted())
                        <span class="{{ $muted }}">Already friends</span>
                    @elseif ($f?->isPending() && (int) $f->requester_id === auth()->id())
                        <span class="{{ $muted }}">Request sent</span>
                    @elseif ($f?->isPending())
                        <button wire:click="sendRequest({{ $u->id }})" class="{{ $btnPri }}">Accept their request</button>
                    @else
                        <button wire:click="sendRequest({{ $u->id }})" class="{{ $btnPri }}">Send friend request</button>
                    @endif
                </div>
            @elseif (str_contains($searchEmail, '@'))
                <p class="{{ $muted }} mt-3">No active user with that email.</p>
            @endif
        </section>

        {{-- ── Incoming requests ── --}}
        @if ($this->incoming->isNotEmpty())
            <section class="{{ $card }} p-6">
                <h3 class="{{ $h3 }} mb-2">Friend requests</h3>
                @foreach ($this->incoming as $f)
                    <div class="{{ $row }}" wire:key="in-{{ $f->id }}">
                        <span class="text-sm text-gray-900 dark:text-gray-100">{{ $f->requester->name }}</span>
                        <span class="flex gap-2">
                            <button wire:click="accept({{ $f->id }})" class="{{ $btnPri }}">Accept</button>
                            <button wire:click="decline({{ $f->id }})" class="{{ $btn }}">Decline</button>
                        </span>
                    </div>
                @endforeach
            </section>
        @endif

        {{-- ── Trade invitations ── --}}
        @if ($this->invitations->isNotEmpty())
            <section class="{{ $card }} p-6">
                <h3 class="{{ $h3 }} mb-2">Trade invitations</h3>
                @foreach ($this->invitations as $inv)
                    @php $t = $inv->trade; @endphp
                    <div class="{{ $row }}" wire:key="ti-{{ $inv->id }}">
                        <span class="text-sm text-gray-900 dark:text-gray-100">
                            {{ $inv->invitedBy->name }}
                            <span class="text-gray-500 dark:text-gray-400">·</span>
                            {{ ucfirst($t->direction->value) }} {{ $t->quantity }} {{ $t->instrument }}
                            <span class="text-gray-500 dark:text-gray-400">· {{ $t->entry_at->format('M j, Y') }}</span>
                        </span>
                        <span class="flex gap-2">
                            @if ($inv->isPending())
                                <button wire:click="acceptInvitation({{ $inv->id }})" class="{{ $btnPri }}">Accept</button>
                                <button wire:click="declineInvitation({{ $inv->id }})" class="{{ $btn }}">Decline</button>
                            @endif
                        </span>
                    </div>
                @endforeach
            </section>
        @endif

        {{-- ── Friends ── --}}
        <section class="{{ $card }} p-6">
            <h3 class="{{ $h3 }} mb-2">Your friends</h3>
            @forelse ($this->friends as $f)
                @php $other = $f->otherUser(auth()->user()); @endphp
                <div class="{{ $row }}" wire:key="fr-{{ $f->id }}">
                    <span class="text-sm text-gray-900 dark:text-gray-100">{{ $other->name }}</span>
                    <button wire:click="remove({{ $f->id }})"
                        wire:confirm="Remove {{ $other->name }} as a friend? Any trade invitations between you will be revoked."
                        class="{{ $btn }} hover:text-red-600 dark:hover:text-red-400">Remove</button>
                </div>
            @empty
                <p class="{{ $muted }}">No friends yet.</p>
            @endforelse
        </section>

        {{-- ── Sent requests ── --}}
        @if ($this->outgoing->isNotEmpty())
            <section class="{{ $card }} p-6">
                <h3 class="{{ $h3 }} mb-2">Sent requests</h3>
                @foreach ($this->outgoing as $f)
                    <div class="{{ $row }}" wire:key="out-{{ $f->id }}">
                        <span class="text-sm text-gray-900 dark:text-gray-100">{{ $f->recipient->name }}</span>
                        <button wire:click="cancel({{ $f->id }})" class="{{ $btn }}">Cancel</button>
                    </div>
                @endforeach
            </section>
        @endif
    </div>
</div>
