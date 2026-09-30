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
use Illuminate\Http\UploadedFile;
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
        'name'             => 'Topstep-Combine-7731',
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

    $response->assertDontSee('Topstep-Combine-7731')
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

// ---------------------------------------------------------------------------
// The owner's thread inside the journal trade page
// ---------------------------------------------------------------------------

function ownerJournal(object $f)
{
    return Livewire::actingAs($f->owner)
        ->test('journal.trade-journal', ['journal' => $f->owner->journal])
        ->set('selectedUuid', $f->trade->uuid);
}

test('the owner sees the comment thread on their trade in the journal', function () {
    $f = sharedFixture();
    $top = TradeComment::factory()->create(['trade_id' => $f->trade->id, 'user_id' => $f->friend->id, 'body' => 'Why size up here?']);
    TradeComment::factory()->create(['trade_id' => $f->trade->id, 'user_id' => $f->owner->id, 'parent_comment_id' => $top->id, 'body' => 'Trend day.']);

    ownerJournal($f)
        ->assertSee('Conversation')
        ->assertSeeInOrder(['Friend Finn', 'Why size up here?', 'Owner Olive', 'Trend day.']);
});

test('the owner can comment from the journal, and the invitee is emailed a link to the conversation page', function () {
    $f = sharedFixture();
    Mail::fake();

    ownerJournal($f)->set('commentBody', 'What would you have done?')->call('postComment')->assertHasNoErrors();

    $c = TradeComment::sole();
    expect($c->user_id)->toBe($f->owner->id)->and($c->parent_comment_id)->toBeNull();

    Mail::assertSent(NewTradeCommentMail::class, 1);
    Mail::assertSent(NewTradeCommentMail::class, fn ($m) => $m->hasTo($f->friend->email)
        && $m->url === route('trades.shared', $f->trade).'#comment-'.$c->id);
});

test('the owner can reply to a friend comment from the journal', function () {
    $f = sharedFixture();
    $top = TradeComment::factory()->create(['trade_id' => $f->trade->id, 'user_id' => $f->friend->id]);
    Mail::fake();

    ownerJournal($f)->call('startReply', $top->id)->set('replyBody', 'Good question.')->call('postReply')->assertHasNoErrors();

    $reply = TradeComment::where('parent_comment_id', $top->id)->sole();
    expect($reply->user_id)->toBe($f->owner->id)->and($reply->body)->toBe('Good question.');
    Mail::assertSent(NewTradeCommentMail::class, fn ($m) => $m->hasTo($f->friend->email));
});

test('the journal enforces the same one-level nesting and same-trade parent rules', function () {
    $f = sharedFixture();
    $top   = TradeComment::factory()->create(['trade_id' => $f->trade->id, 'user_id' => $f->friend->id]);
    $reply = TradeComment::factory()->create(['trade_id' => $f->trade->id, 'user_id' => $f->owner->id, 'parent_comment_id' => $top->id]);
    $elsewhere = TradeComment::factory()->create(['trade_id' => $f->otherTrade->id, 'user_id' => $f->owner->id]);

    ownerJournal($f)->call('startReply', $reply->id)->set('replyBody', 'nested')->call('postReply')
        ->assertHasErrors('parent_comment_id');
    ownerJournal($f)->call('startReply', $elsewhere->id)->set('replyBody', 'wrong trade')->call('postReply')
        ->assertHasErrors('parent_comment_id');

    expect(TradeComment::count())->toBe(3);
});

test('the journal thread is only for the owner own trades', function () {
    $f = sharedFixture();
    $intruder = User::factory()->create();

    // Another user pointing their journal at this trade resolves nothing.
    Livewire::actingAs($intruder)
        ->test('journal.trade-journal', ['journal' => $intruder->journal])
        ->set('selectedUuid', $f->trade->uuid)
        ->set('commentBody', 'hi')
        ->call('postComment')
        ->assertNotFound();

    expect(TradeComment::count())->toBe(0);
});

test('a trade with no invitations and no comments shows no conversation box', function () {
    $f = sharedFixture();
    $f->invitation->delete();

    ownerJournal($f)->assertDontSee('Conversation');
});

test('a friend comment emails the owner a link back to the trade in their journal', function () {
    $f = sharedFixture();
    Mail::fake();

    $this->actingAs($f->friend)->post(commentUrl($f->trade), ['body' => 'Nice']);

    Mail::assertSent(NewTradeCommentMail::class, fn ($m) => $m->hasTo($f->owner->email)
        && str_starts_with($m->url, route('journal.index', ['trade' => $f->trade->uuid])));
});

test('commenting is rate limited across the journal and the conversation page', function () {
    $f = sharedFixture();
    Mail::fake();

    for ($i = 0; $i < 20; $i++) {
        ownerJournal($f)->set('commentBody', "c{$i}")->call('postComment')->assertHasNoErrors();
    }
    ownerJournal($f)->set('commentBody', 'one too many')->call('postComment')->assertHasErrors('body');
    $this->actingAs($f->owner)->postJson(commentUrl($f->trade), ['body' => 'also too many'])->assertStatus(422);

    expect(TradeComment::count())->toBe(20);
});

// ---------------------------------------------------------------------------
// Images on comments & replies
// ---------------------------------------------------------------------------

function imageUrl(Trade $trade, TradeComment $comment): string
{
    return route('trades.shared.comment-image', [$trade, $comment]);
}

test('an invitee can attach an image to a reply; the thread shows a link, not the image', function () {
    Storage::fake('local');
    $f = sharedFixture();
    $top = TradeComment::factory()->create(['trade_id' => $f->trade->id, 'user_id' => $f->owner->id]);

    $this->actingAs($f->friend)->post(commentUrl($f->trade), [
        'body' => 'See my chart', 'parent_comment_id' => $top->id,
        'image' => UploadedFile::fake()->image('my-chart.png', 800, 600),
    ])->assertRedirect();

    $reply = TradeComment::where('parent_comment_id', $top->id)->sole();
    expect($reply->hasImage())->toBeTrue()
        ->and($reply->image_mime_type)->toBe('image/png')
        // Server-generated name, never the client's filename.
        ->and($reply->image_path)->not->toContain('my-chart');
    Storage::disk('local')->assertExists($reply->image_path);

    $html = $this->actingAs($f->friend)->get(sharedUrl($f->trade))->assertOk()->assertSee('View image')->getContent();
    // The only reference to the image is inside the (closed) modal dialog.
    expect(substr_count($html, imageUrl($f->trade, $reply)))->toBe(1)
        ->and($html)->toMatch('#<dialog[^>]*>(?:(?!</dialog>).)*'.preg_quote(e(imageUrl($f->trade, $reply)), '#').'#s');
});

test('a top-level comment can carry an image too', function () {
    Storage::fake('local');
    $f = sharedFixture();

    $this->actingAs($f->friend)->post(commentUrl($f->trade), [
        'body' => 'Here is mine', 'image' => UploadedFile::fake()->image('x.jpg'),
    ]);

    expect(TradeComment::sole()->hasImage())->toBeTrue();
});

test('only images are accepted, SVG included in the rejects, and size is capped', function (UploadedFile $file) {
    Storage::fake('local');
    $f = sharedFixture();

    $this->actingAs($f->friend)
        ->postJson(commentUrl($f->trade), ['body' => 'x', 'image' => $file])
        ->assertStatus(422)
        ->assertJsonValidationErrors('image');

    expect(TradeComment::count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBeEmpty();
})->with([
    'pdf'      => fn () => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
    'svg'      => fn () => UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
    'too big'  => fn () => UploadedFile::fake()->image('big.png')->size(10241),
]);

test('comment images are served only to people who can view the trade', function () {
    Storage::fake('local');
    $f = sharedFixture();
    $this->actingAs($f->friend)->post(commentUrl($f->trade), ['body' => 'x', 'image' => UploadedFile::fake()->image('a.png')]);
    $comment = TradeComment::sole();
    $outsider = User::factory()->create();

    $this->actingAs($f->friend)->get(imageUrl($f->trade, $comment))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->actingAs($f->owner)->get(imageUrl($f->trade, $comment))->assertOk();
    $this->actingAs($outsider)->get(imageUrl($f->trade, $comment))->assertNotFound();

    // Revoked mid-session: the image goes with everything else.
    $f->invitation->update(['status' => InvitationStatus::Revoked]);
    $this->actingAs($f->friend)->get(imageUrl($f->trade, $comment))->assertNotFound();
});

test('a comment image cannot be fetched through another trade URL', function () {
    Storage::fake('local');
    $f = sharedFixture();
    // Friend is also invited to a second trade of the owner's.
    TradeInvitation::factory()->create(['trade_id' => $f->otherTrade->id, 'invited_user_id' => $f->friend->id, 'invited_by_user_id' => $f->owner->id, 'status' => InvitationStatus::Accepted]);
    $this->actingAs($f->owner)->post(commentUrl($f->trade), ['body' => 'x', 'image' => UploadedFile::fake()->image('a.png')]);
    $comment = TradeComment::sole();
    $plain = TradeComment::factory()->create(['trade_id' => $f->trade->id, 'user_id' => $f->owner->id]);

    $this->actingAs($f->friend)->get(imageUrl($f->otherTrade, $comment))->assertNotFound();
    $this->actingAs($f->friend)->get(imageUrl($f->trade, $plain))->assertNotFound();
});

test('the owner can attach an image to a reply from the journal', function () {
    Storage::fake('local');
    $f = sharedFixture();
    $top = TradeComment::factory()->create(['trade_id' => $f->trade->id, 'user_id' => $f->friend->id]);

    ownerJournal($f)
        ->call('startReply', $top->id)
        ->set('replyBody', 'Annotated')
        ->set('replyImage', UploadedFile::fake()->image('mine.png'))
        ->call('postReply')
        ->assertHasNoErrors();

    $reply = TradeComment::where('parent_comment_id', $top->id)->sole();
    expect($reply->hasImage())->toBeTrue();
    Storage::disk('local')->assertExists($reply->image_path);

    ownerJournal($f)->assertSee('View image')->assertSee(imageUrl($f->trade, $reply));
});

test('the journal rejects a non-image attachment', function () {
    Storage::fake('local');
    $f = sharedFixture();

    ownerJournal($f)
        ->set('commentBody', 'x')
        ->set('commentImage', UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'))
        ->call('postComment')
        ->assertHasErrors('image');

    expect(TradeComment::count())->toBe(0);
});

test('deleting a trade also deletes its comment image files', function () {
    Storage::fake('local');
    $f = sharedFixture();
    $this->actingAs($f->friend)->post(commentUrl($f->trade), ['body' => 'x', 'image' => UploadedFile::fake()->image('a.png')]);
    $path = TradeComment::sole()->image_path;

    Livewire::actingAs($f->owner)
        ->test('journal.trade-journal', ['journal' => $f->owner->journal])
        ->call('deleteTrade', $f->trade->uuid);

    Storage::disk('local')->assertMissing($path);
});

// ---------------------------------------------------------------------------
// Expanding the trade's chart screenshot in a modal
// ---------------------------------------------------------------------------

test('the journal chart has an expand icon that opens the screenshot in a modal', function () {
    $f = sharedFixture();
    $shot = TradeScreenshot::factory()->create(['trade_id' => $f->trade->id, 'mime_type' => 'image/png', 'source' => ScreenshotSource::Nt8]);
    $url = e(route('journal.screenshot', [$f->trade, $shot]));

    $html = ownerJournal($f)->assertSee('Expand image')->html();

    // An icon button (not a link) opens a <dialog> holding the full image.
    expect($html)->toMatch('#<button[^>]*title="Expand image"#')
        ->and($html)->toMatch('#<dialog[^>]*>(?:(?!</dialog>).)*'.preg_quote($url, '#').'#s');
});

test('the conversation page chart can be expanded in a modal too', function () {
    $f = sharedFixture();
    $shot = TradeScreenshot::factory()->create(['trade_id' => $f->trade->id, 'mime_type' => 'image/png', 'source' => ScreenshotSource::Nt8]);
    $url = e(route('trades.shared.screenshot', [$f->trade, $shot]));

    $html = $this->actingAs($f->friend)->get(sharedUrl($f->trade))->assertOk()->getContent();

    expect($html)->toMatch('#<button[^>]*title="Expand image"#')
        ->and($html)->toMatch('#<dialog[^>]*>(?:(?!</dialog>).)*'.preg_quote($url, '#').'#s');
});
