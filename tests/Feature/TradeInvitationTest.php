<?php

use App\Enums\FriendshipStatus;
use App\Enums\InvitationStatus;
use App\Mail\TradeInvitationMail;
use App\Models\Account;
use App\Models\Friendship;
use App\Models\Trade;
use App\Models\TradeInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function ownedTrade(User $owner, array $attrs = []): Trade
{
    $journal = $owner->journal;
    $account = Account::firstWhere(['journal_id' => $journal->id, 'name' => 'Secret Funded 50K'])
        ?? Account::factory()->create(['journal_id' => $journal->id, 'name' => 'Secret Funded 50K']);

    return Trade::factory()->create(array_merge([
        'journal_id' => $journal->id,
        'account_id' => $account->id,
        'entry_at'   => now()->subHours(2),
        'exit_at'    => now()->subHours(1),
    ], $attrs));
}

function befriend(User $a, User $b): Friendship
{
    return Friendship::factory()->accepted()->create(['requester_id' => $a->id, 'recipient_id' => $b->id]);
}

function invite(Trade $trade, User $owner, User $friend, InvitationStatus $status = InvitationStatus::Pending): TradeInvitation
{
    return TradeInvitation::factory()->create([
        'trade_id'           => $trade->id,
        'invited_user_id'    => $friend->id,
        'invited_by_user_id' => $owner->id,
        'status'             => $status,
    ]);
}

function journalFor(User $owner, Trade $trade)
{
    return Livewire::actingAs($owner)
        ->test('journal.trade-journal', ['journal' => $owner->journal])
        ->set('selectedUuid', $trade->uuid);
}

// ---------------------------------------------------------------------------
// Policy matrix — acceptance is required, and checked against THIS trade
// ---------------------------------------------------------------------------

test('only the owner or an accepted, still-friended invitee can view a shared trade', function (?InvitationStatus $status, bool $friends, bool $expected) {
    [$owner, $friend] = User::factory()->count(2)->create();
    $trade = ownedTrade($owner);
    if ($friends) {
        befriend($owner, $friend);
    }
    if ($status) {
        invite($trade, $owner, $friend, $status);
    }

    expect(Gate::forUser($friend)->allows('viewShared', $trade))->toBe($expected)
        ->and(Gate::forUser($friend)->allows('comment', $trade))->toBe($expected)
        ->and(Gate::forUser($owner)->allows('viewShared', $trade))->toBeTrue();
})->with([
    'accepted invitation'              => [InvitationStatus::Accepted, true, true],
    'pending invitation'               => [InvitationStatus::Pending, true, false],
    'declined invitation'              => [InvitationStatus::Declined, true, false],
    'revoked invitation'               => [InvitationStatus::Revoked, true, false],
    'friends but no invitation'        => [null, true, false],
    'accepted but no longer friends'   => [InvitationStatus::Accepted, false, false],
]);

test('an accepted invitation to one trade grants nothing on the owner other trades', function () {
    [$owner, $friend] = User::factory()->count(2)->create();
    befriend($owner, $friend);
    $invited = ownedTrade($owner);
    $other   = ownedTrade($owner);
    invite($invited, $owner, $friend, InvitationStatus::Accepted);

    expect(Gate::forUser($friend)->allows('viewShared', $invited))->toBeTrue()
        ->and(Gate::forUser($friend)->allows('viewShared', $other))->toBeFalse();
});

test('only the owner may invite to a trade', function () {
    [$owner, $friend] = User::factory()->count(2)->create();
    befriend($owner, $friend);
    $trade = ownedTrade($owner);
    invite($trade, $owner, $friend, InvitationStatus::Accepted);

    expect(Gate::forUser($owner)->allows('invite', $trade))->toBeTrue()
        ->and(Gate::forUser($friend)->allows('invite', $trade))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Owner: invite & revoke from the journal page
// ---------------------------------------------------------------------------

test('the owner can invite an accepted friend, who is emailed', function () {
    Mail::fake();
    [$owner, $friend] = User::factory()->count(2)->create();
    befriend($owner, $friend);
    $trade = ownedTrade($owner);

    journalFor($owner, $trade)->call('inviteFriend', $friend->id);

    $inv = TradeInvitation::sole();
    expect($inv->trade_id)->toBe($trade->id)
        ->and($inv->invited_user_id)->toBe($friend->id)
        ->and($inv->invited_by_user_id)->toBe($owner->id)
        ->and($inv->status)->toBe(InvitationStatus::Pending);
    Mail::assertSent(TradeInvitationMail::class, fn ($m) => $m->hasTo($friend->email) && ! $m->hasTo($owner->email));
});

test('the invitation email carries no account information', function () {
    [$owner, $friend] = User::factory()->count(2)->sequence(['name' => 'Administration'], [])->create();
    $trade = ownedTrade($owner);
    $inv   = invite($trade, $owner, $friend);

    $html = (new TradeInvitationMail($inv))->render();

    expect($html)->toContain('Administration')->and($html)->not->toContain('Secret Funded 50K');
});

test('cannot invite someone who is not an accepted friend', function () {
    Mail::fake();
    [$owner, $pendingFriend, $stranger] = User::factory()->count(3)->create();
    Friendship::factory()->create(['requester_id' => $owner->id, 'recipient_id' => $pendingFriend->id, 'status' => FriendshipStatus::Pending]);
    $trade = ownedTrade($owner);

    journalFor($owner, $trade)->call('inviteFriend', $pendingFriend->id)->call('inviteFriend', $stranger->id);

    expect(TradeInvitation::count())->toBe(0);
    Mail::assertNotSent(TradeInvitationMail::class);
});

test('cannot invite to a trade in someone else journal', function () {
    Mail::fake();
    [$owner, $attacker, $friend] = User::factory()->count(3)->create();
    befriend($attacker, $friend);
    $victimTrade = ownedTrade($owner);

    // The journal page only resolves trades in the viewer's own journal.
    journalFor($attacker, $victimTrade)->call('inviteFriend', $friend->id);

    expect(TradeInvitation::count())->toBe(0);
});

test('re-inviting after a revoke resets the same row to pending and emails again', function () {
    Mail::fake();
    [$owner, $friend] = User::factory()->count(2)->create();
    befriend($owner, $friend);
    $trade = ownedTrade($owner);
    invite($trade, $owner, $friend, InvitationStatus::Revoked);

    journalFor($owner, $trade)->call('inviteFriend', $friend->id);

    expect(TradeInvitation::sole()->status)->toBe(InvitationStatus::Pending);
    Mail::assertSent(TradeInvitationMail::class, 1);
});

test('inviting someone already invited does not duplicate or re-send', function () {
    Mail::fake();
    [$owner, $friend] = User::factory()->count(2)->create();
    befriend($owner, $friend);
    $trade = ownedTrade($owner);
    invite($trade, $owner, $friend, InvitationStatus::Accepted);

    journalFor($owner, $trade)->call('inviteFriend', $friend->id);

    expect(TradeInvitation::sole()->status)->toBe(InvitationStatus::Accepted);
    Mail::assertNotSent(TradeInvitationMail::class);
});

test('the owner can revoke an invitation', function () {
    [$owner, $friend] = User::factory()->count(2)->create();
    befriend($owner, $friend);
    $trade = ownedTrade($owner);
    $inv   = invite($trade, $owner, $friend, InvitationStatus::Accepted);

    journalFor($owner, $trade)->call('revokeInvitation', $inv->id);

    expect($inv->fresh()->status)->toBe(InvitationStatus::Revoked);
});

test('the owner cannot revoke an invitation belonging to a different trade', function () {
    [$owner, $other, $friend] = User::factory()->count(3)->create();
    $ownTrade   = ownedTrade($owner);
    $otherTrade = ownedTrade($other);
    $inv        = invite($otherTrade, $other, $friend, InvitationStatus::Accepted);

    journalFor($owner, $ownTrade)->call('revokeInvitation', $inv->id)->assertNotFound();

    expect($inv->fresh()->status)->toBe(InvitationStatus::Accepted);
});

test('the invite panel lists friends and their invitation status', function () {
    [$owner, $friend] = User::factory()->count(2)->create();
    $friend->update(['name' => 'Invitable Ivy']);
    befriend($owner, $friend);
    $trade = ownedTrade($owner);

    journalFor($owner, $trade)->set('showInvite', true)->assertSee('Invitable Ivy');
});

// ---------------------------------------------------------------------------
// Invitee: accept / decline from the friends page
// ---------------------------------------------------------------------------

test('the invitee sees a pending invitation without any account details', function () {
    [$owner, $friend] = User::factory()->count(2)->create();
    befriend($owner, $friend);
    $trade = ownedTrade($owner, ['instrument' => 'NQ 12-26']);
    invite($trade, $owner, $friend);

    Livewire::actingAs($friend)->test('friends.manage')
        ->assertSee($owner->name)
        ->assertSee('NQ 12-26')
        ->assertDontSee('Secret Funded 50K');
});

test('the invitee can accept or decline their invitation', function () {
    [$owner, $friend] = User::factory()->count(2)->create();
    befriend($owner, $friend);
    $a = invite(ownedTrade($owner), $owner, $friend);
    $b = invite(ownedTrade($owner), $owner, $friend);

    Livewire::actingAs($friend)->test('friends.manage')
        ->call('acceptInvitation', $a->id)
        ->call('declineInvitation', $b->id);

    expect($a->fresh()->status)->toBe(InvitationStatus::Accepted)
        ->and($b->fresh()->status)->toBe(InvitationStatus::Declined);
});

test('nobody but the invitee can accept, and a revoked invitation cannot be accepted', function () {
    [$owner, $friend, $outsider] = User::factory()->count(3)->create();
    befriend($owner, $friend);
    $trade   = ownedTrade($owner);
    $pending = invite($trade, $owner, $friend);
    $revoked = invite(ownedTrade($owner), $owner, $friend, InvitationStatus::Revoked);

    Livewire::actingAs($outsider)->test('friends.manage')->call('acceptInvitation', $pending->id)->assertNotFound();
    Livewire::actingAs($owner)->test('friends.manage')->call('acceptInvitation', $pending->id)->assertNotFound();
    Livewire::actingAs($friend)->test('friends.manage')->call('acceptInvitation', $revoked->id)->assertNotFound();

    expect($pending->fresh()->status)->toBe(InvitationStatus::Pending)
        ->and($revoked->fresh()->status)->toBe(InvitationStatus::Revoked);
});

// ---------------------------------------------------------------------------
// Ending a friendship
// ---------------------------------------------------------------------------

test('removing a friend revokes active invitations in both directions', function () {
    [$a, $b] = User::factory()->count(2)->create();
    $friendship = befriend($a, $b);
    $aToB = invite(ownedTrade($a), $a, $b, InvitationStatus::Accepted);
    $bToA = invite(ownedTrade($b), $b, $a, InvitationStatus::Pending);
    $old  = invite(ownedTrade($a), $a, $b, InvitationStatus::Declined);

    Livewire::actingAs($a)->test('friends.manage')->call('remove', $friendship->id);

    expect($aToB->fresh()->status)->toBe(InvitationStatus::Revoked)
        ->and($bToA->fresh()->status)->toBe(InvitationStatus::Revoked)
        ->and($old->fresh()->status)->toBe(InvitationStatus::Declined);
});

// ---------------------------------------------------------------------------
// Trade-list indicator & link to the shared page
// ---------------------------------------------------------------------------

test('the trade list flags trades with a live invitation or a comment thread', function () {
    [$owner, $friend] = User::factory()->count(2)->create();
    befriend($owner, $friend);
    $invited   = ownedTrade($owner);
    $discussed = ownedTrade($owner);
    $revoked   = ownedTrade($owner);
    invite($invited, $owner, $friend, InvitationStatus::Pending);
    invite($discussed, $owner, $friend, InvitationStatus::Accepted);
    invite($revoked, $owner, $friend, InvitationStatus::Revoked);
    \App\Models\TradeComment::factory()->count(2)->create(['trade_id' => $discussed->id, 'user_id' => $friend->id]);

    $trades = Livewire::actingAs($owner)
        ->test('journal.trade-journal', ['journal' => $owner->journal])
        ->set('dateFrom', '')->set('dateTo', '')
        ->assertSee('Shared')
        ->assertSee('2 comments')
        ->instance()->trades->keyBy('id');

    expect($trades[$invited->id]->active_invitations_count)->toBe(1)
        ->and($trades[$discussed->id]->comments_count)->toBe(2)
        ->and($trades[$revoked->id]->active_invitations_count)->toBe(0);
});

test('the invite panel links the owner to the shared page', function () {
    $owner = User::factory()->create();
    $trade = ownedTrade($owner);

    journalFor($owner, $trade)->set('showInvite', true)->assertSee(route('trades.shared', $trade));
});
