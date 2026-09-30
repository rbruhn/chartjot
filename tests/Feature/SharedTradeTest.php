<?php

use App\Enums\InvitationStatus;
use App\Enums\NotePhase;
use App\Enums\ScreenshotSource;
use App\Mail\NewTradeCommentMail;
use App\Models\Account;
use App\Models\Friendship;
use App\Models\Trade;
use App\Models\TradeComment;
use App\Models\TradeInvitation;
use App\Models\TradeNote;
use App\Models\TradeScreenshot;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

// ---------------------------------------------------------------------------
// Fixture: an owner with two trades in a funded account, and a friend with
// an invitation to the first trade in the given status.
// ---------------------------------------------------------------------------

function sharedFixture(InvitationStatus $status = InvitationStatus::Accepted): object
{
    [$owner, $friend] = User::factory()->count(2)->sequence(['name' => 'Owner Olive'], ['name' => 'Friend Finn'])->create();
    Friendship::factory()->accepted()->create(['requester_id' => $owner->id, 'recipient_id' => $friend->id]);

    $account = Account::factory()->create([
        'journal_id'       => $owner->journal->id,
        'name'             => 'Test-Combine-7731',
        'connection'       => 'RithmicSecret',
        'starting_balance' => 150000,
    ]);

    $trade = Trade::factory()->create([
        'journal_id' => $owner->journal->id, 'account_id' => $account->id,
        'instrument' => 'ES 12-26', 'instrument_symbol' => 'ES',
        'entry_price' => '5000.25', 'exit_price' => '5004.75', 'net_pnl' => '217.40',
    ]);
    $otherTrade = Trade::factory()->create([
        'journal_id' => $owner->journal->id, 'account_id' => $account->id,
        'instrument' => 'CL 11-26', 'instrument_symbol' => 'CL', 'net_pnl' => '-999.99',
    ]);

    TradeNote::factory()->create(['trade_id' => $trade->id, 'body' => 'Waited for the pullback', 'phase' => NotePhase::PostTrade]);
    TradeNote::factory()->create(['trade_id' => $otherTrade->id, 'body' => 'Revenge trade on crude']);

    $invitation = TradeInvitation::factory()->create([
        'trade_id' => $trade->id, 'invited_user_id' => $friend->id, 'invited_by_user_id' => $owner->id, 'status' => $status,
    ]);

    return (object) compact('owner', 'friend', 'account', 'trade', 'otherTrade', 'invitation');
}

function sharedUrl(Trade $trade): string
{
    return route('trades.shared', $trade);
}

function commentUrl(Trade $trade): string
{
    return route('trades.shared.comments.store', $trade);
}

/** Recursively look for any Eloquent model in view data. */
function containsModel(mixed $value): bool
{
    if ($value instanceof Model) {
        return true;
    }
    if (is_iterable($value)) {
        foreach ($value as $v) {
            if (containsModel($v)) {
                return true;
            }
        }
    }

    return false;
}

// ---------------------------------------------------------------------------
// Who can open the page
// ---------------------------------------------------------------------------

test('an accepted invitee can view the shared trade', function () {
    $f = sharedFixture();

    $this->actingAs($f->friend)->get(sharedUrl($f->trade))
        ->assertOk()
        ->assertSee('ES 12-26')
        ->assertSee('5,004.75')
        ->assertSee('+$217.40')
        ->assertSee('Waited for the pullback')
        ->assertSee('Owner Olive');
});

test('the owner can view their own shared trade', function () {
    $f = sharedFixture();

    $this->actingAs($f->owner)->get(sharedUrl($f->trade))->assertOk();
});

test('an invitee who has not accepted cannot view or comment', function (InvitationStatus $status) {
    $f = sharedFixture($status);

    $this->actingAs($f->friend)->get(sharedUrl($f->trade))->assertNotFound();
    $this->actingAs($f->friend)->post(commentUrl($f->trade), ['body' => 'hi'])->assertNotFound();

    expect(TradeComment::count())->toBe(0);
})->with([
    'pending'  => [InvitationStatus::Pending],
    'declined' => [InvitationStatus::Declined],
    'revoked'  => [InvitationStatus::Revoked],
]);

test('a user with no invitation cannot reach the page by URL', function () {
    $f = sharedFixture();
    $stranger = User::factory()->create();
    Friendship::factory()->accepted()->create(['requester_id' => $f->owner->id, 'recipient_id' => $stranger->id]);

    // Even a friend of the owner gets nothing without an invitation.
    $this->actingAs($stranger)->get(sharedUrl($f->trade))->assertNotFound();
    $this->actingAs($stranger)->post(commentUrl($f->trade), ['body' => 'hi'])->assertNotFound();
});

test('changing the UUID to another trade is rejected', function () {
    $f = sharedFixture();
    $someoneElse = User::factory()->create();
    $foreignTrade = Trade::factory()->create([
        'journal_id' => $someoneElse->journal->id,
        'account_id' => Account::factory()->create(['journal_id' => $someoneElse->journal->id])->id,
    ]);

    // Same owner, different trade.
    $this->actingAs($f->friend)->get(sharedUrl($f->otherTrade))->assertNotFound();
    $this->actingAs($f->friend)->post(commentUrl($f->otherTrade), ['body' => 'hi'])->assertNotFound();
    // A different owner's trade entirely.
    $this->actingAs($f->friend)->get(sharedUrl($foreignTrade))->assertNotFound();

    expect(TradeComment::count())->toBe(0);
});

test('guests are sent to login', function () {
    $f = sharedFixture();

    $this->get(sharedUrl($f->trade))->assertRedirect('/login');
    $this->post(commentUrl($f->trade), ['body' => 'hi'])->assertRedirect('/login');
});

// ---------------------------------------------------------------------------
// Revocation mid-session — the invitee already has a working session & URL
// ---------------------------------------------------------------------------

test('revoking cuts off an invitee on their very next page load and comment post', function () {
    $f = sharedFixture();

    // Session established, page and posting both work.
    $this->actingAs($f->friend)->get(sharedUrl($f->trade))->assertOk();
    $this->actingAs($f->friend)->post(commentUrl($f->trade), ['body' => 'Nice entry'])->assertRedirect();
    expect(TradeComment::count())->toBe(1);

    // Owner revokes through the real UI action, not a direct DB write.
    Livewire::actingAs($f->owner)
        ->test('journal.trade-journal', ['journal' => $f->owner->journal])
        ->set('selectedUuid', $f->trade->uuid)
        ->call('revokeInvitation', $f->invitation->id);

    // Same session, same URL, next request: rejected.
    $this->actingAs($f->friend)->get(sharedUrl($f->trade))->assertNotFound();
    $this->actingAs($f->friend)->post(commentUrl($f->trade), ['body' => 'Still here?'])->assertNotFound();
    $this->actingAs($f->friend)->get(route('trades.shared.screenshot', [$f->trade, TradeScreenshot::factory()->create(['trade_id' => $f->trade->id])]))->assertNotFound();

    expect(TradeComment::count())->toBe(1);
});

test('ending the friendship cuts off an accepted invitee on the next request', function () {
    $f = sharedFixture();
    $this->actingAs($f->friend)->get(sharedUrl($f->trade))->assertOk();

    $friendship = Friendship::between($f->owner, $f->friend)->sole();
    Livewire::actingAs($f->owner)->test('friends.manage')->call('remove', $friendship->id);

    $this->actingAs($f->friend)->get(sharedUrl($f->trade))->assertNotFound();
    $this->actingAs($f->friend)->post(commentUrl($f->trade), ['body' => 'hi'])->assertNotFound();
});

// ---------------------------------------------------------------------------
// Comments & one-level nesting
// ---------------------------------------------------------------------------

test('an accepted invitee can post a top-level comment and reply to a top-level comment', function () {
    $f = sharedFixture();

    $this->actingAs($f->friend)->post(commentUrl($f->trade), ['body' => 'Why size up here?'])
        ->assertRedirect(sharedUrl($f->trade).'#comment-'.TradeComment::max('id'));
    $top = TradeComment::sole();

    $this->actingAs($f->owner)->post(commentUrl($f->trade), ['body' => 'A+ setup', 'parent_comment_id' => $top->id])->assertRedirect();
    $this->actingAs($f->friend)->post(commentUrl($f->trade), ['body' => 'Fair', 'parent_comment_id' => $top->id])->assertRedirect();

    expect($top->user_id)->toBe($f->friend->id)
        ->and($top->parent_comment_id)->toBeNull()
        ->and($top->replies()->pluck('body')->all())->toBe(['A+ setup', 'Fair']);

    $this->actingAs($f->friend)->get(sharedUrl($f->trade))
        ->assertSeeInOrder(['Why size up here?', 'A+ setup', 'Fair']);
});

test('a reply to a reply is rejected with 422', function () {
    $f = sharedFixture();
    $top   = TradeComment::factory()->create(['trade_id' => $f->trade->id, 'user_id' => $f->owner->id]);
    $reply = TradeComment::factory()->create(['trade_id' => $f->trade->id, 'user_id' => $f->friend->id, 'parent_comment_id' => $top->id]);

    $this->actingAs($f->friend)
        ->postJson(commentUrl($f->trade), ['body' => 'nested', 'parent_comment_id' => $reply->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('parent_comment_id');

    // A plain form post fails validation the same way.
    $this->actingAs($f->friend)
        ->post(commentUrl($f->trade), ['body' => 'nested', 'parent_comment_id' => $reply->id])
        ->assertSessionHasErrors('parent_comment_id');

    expect(TradeComment::count())->toBe(2);
});

test('a reply cannot target a comment on a different trade', function () {
    $f = sharedFixture();
    $elsewhere = TradeComment::factory()->create(['trade_id' => $f->otherTrade->id, 'user_id' => $f->owner->id]);

    $this->actingAs($f->friend)
        ->postJson(commentUrl($f->trade), ['body' => 'sneaky', 'parent_comment_id' => $elsewhere->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('parent_comment_id');

    expect(TradeComment::where('trade_id', $f->trade->id)->count())->toBe(0);
});

test('comment body is required and capped', function () {
    $f = sharedFixture();

    $this->actingAs($f->friend)->postJson(commentUrl($f->trade), ['body' => ''])->assertStatus(422);
    $this->actingAs($f->friend)->postJson(commentUrl($f->trade), ['body' => str_repeat('x', 5001)])->assertStatus(422);

    expect(TradeComment::count())->toBe(0);
});

test('comment author is always the signed-in user', function () {
    $f = sharedFixture();

    $this->actingAs($f->friend)->post(commentUrl($f->trade), ['body' => 'hi', 'user_id' => $f->owner->id]);

    expect(TradeComment::sole()->user_id)->toBe($f->friend->id);
});

// ---------------------------------------------------------------------------
// Email on each comment
// ---------------------------------------------------------------------------

test('each new comment emails every other participant individually, but not the author', function () {
    $f = sharedFixture();
    $second  = User::factory()->create();
    $revoked = User::factory()->create();
    foreach ([$second, $revoked] as $u) {
        Friendship::factory()->accepted()->create(['requester_id' => $f->owner->id, 'recipient_id' => $u->id]);
    }
    TradeInvitation::factory()->create(['trade_id' => $f->trade->id, 'invited_user_id' => $second->id, 'invited_by_user_id' => $f->owner->id, 'status' => InvitationStatus::Accepted]);
    TradeInvitation::factory()->create(['trade_id' => $f->trade->id, 'invited_user_id' => $revoked->id, 'invited_by_user_id' => $f->owner->id, 'status' => InvitationStatus::Revoked]);
    Mail::fake();

    $this->actingAs($f->friend)->post(commentUrl($f->trade), ['body' => 'Thoughts?']);

    Mail::assertSent(NewTradeCommentMail::class, 2);
    Mail::assertSent(NewTradeCommentMail::class, fn ($m) => $m->hasTo($f->owner->email) && count($m->to) === 1);
    Mail::assertSent(NewTradeCommentMail::class, fn ($m) => $m->hasTo($second->email) && count($m->to) === 1);
    Mail::assertNotSent(NewTradeCommentMail::class, fn ($m) => $m->hasTo($f->friend->email) || $m->hasTo($revoked->email));
});

// ---------------------------------------------------------------------------
// What the page is allowed to contain
// ---------------------------------------------------------------------------

test('the shared page exposes no account data and no other trades', function () {
    $f = sharedFixture();

    $response = $this->actingAs($f->friend)->get(sharedUrl($f->trade))->assertOk();

    $response->assertDontSee('Test-Combine-7731')
        ->assertDontSee('RithmicSecret')
        ->assertDontSee('150,000')
        ->assertDontSee('CL 11-26')
        ->assertDontSee('-$999.99')
        ->assertDontSee('Revenge trade on crude')
        // No route back into the owner's journal from this page.
        ->assertDontSee(route('journal.index'))
        ->assertDontSee(route('journal.accounts'));

    // The view never receives a model at all — only plain arrays of the
    // allowed fields — so a later template edit can't reach $trade->account.
    $data = $response->original->getData();
    expect(containsModel($data))->toBeFalse()
        ->and(array_keys($data['trade']))->toEqualCanonicalizing([
            'uuid', 'title', 'instrument', 'direction', 'quantity', 'entry_price',
            'exit_price', 'points', 'net_pnl', 'date', 'owner_name',
        ]);
});

test('screenshots are served only for the shared trade', function () {
    Storage::fake('local');
    $f = sharedFixture();
    $mine  = TradeScreenshot::factory()->create(['trade_id' => $f->trade->id, 'disk' => 'local', 'path' => 's/mine.png', 'mime_type' => 'image/png', 'source' => ScreenshotSource::Nt8]);
    $other = TradeScreenshot::factory()->create(['trade_id' => $f->otherTrade->id, 'disk' => 'local', 'path' => 's/other.png', 'mime_type' => 'image/png', 'source' => ScreenshotSource::Nt8]);
    Storage::disk('local')->put('s/mine.png', 'png');
    Storage::disk('local')->put('s/other.png', 'png');

    $this->actingAs($f->friend)->get(sharedUrl($f->trade))->assertSee(route('trades.shared.screenshot', [$f->trade, $mine]));
    $this->actingAs($f->friend)->get(route('trades.shared.screenshot', [$f->trade, $mine]))->assertOk();
    // Another trade's screenshot through this trade's URL, and directly.
    $this->actingAs($f->friend)->get(route('trades.shared.screenshot', [$f->trade, $other]))->assertNotFound();
    $this->actingAs($f->friend)->get(route('trades.shared.screenshot', [$f->otherTrade, $other]))->assertNotFound();
});

// ---------------------------------------------------------------------------
// Links in from the rest of the app
// ---------------------------------------------------------------------------

test('accepting an invitation takes the invitee to the shared page', function () {
    $f = sharedFixture(InvitationStatus::Pending);

    Livewire::actingAs($f->friend)->test('friends.manage')
        ->call('acceptInvitation', $f->invitation->id)
        ->assertRedirect(sharedUrl($f->trade));
});

test('deleting a trade removes its invitations and comments', function () {
    $f = sharedFixture();
    TradeComment::factory()->create(['trade_id' => $f->trade->id, 'user_id' => $f->friend->id]);

    $f->trade->delete();

    expect(TradeInvitation::count())->toBe(0)->and(TradeComment::count())->toBe(0);
});
