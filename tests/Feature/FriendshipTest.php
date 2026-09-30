<?php

use App\Enums\FriendshipStatus;
use App\Enums\UserStatus;
use App\Mail\FriendRequestMail;
use App\Models\Friendship;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

function friendsPage(User $user)
{
    return Livewire::actingAs($user)->test('friends.manage');
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------

test('friends page loads for an active user and is linked from the nav', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/friends')->assertOk()->assertSeeLivewire('friends.manage');
    $this->actingAs($user)->get('/journal')->assertSee(route('friends.index'));
});

test('friends page redirects guests to login', function () {
    $this->get('/friends')->assertRedirect('/login');
});

// ---------------------------------------------------------------------------
// Data model
// ---------------------------------------------------------------------------

test('the unordered pair is unique at the database level', function () {
    [$a, $b] = User::factory()->count(2)->create();
    Friendship::create(['requester_id' => $a->id, 'recipient_id' => $b->id, 'status' => FriendshipStatus::Pending]);

    // The reverse direction is the same pair, so the unique index rejects it
    // even when application logic is bypassed.
    expect(fn () => Friendship::create(['requester_id' => $b->id, 'recipient_id' => $a->id, 'status' => FriendshipStatus::Pending]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('a user cannot befriend themselves', function () {
    $a = User::factory()->create();

    expect(fn () => Friendship::create(['requester_id' => $a->id, 'recipient_id' => $a->id, 'status' => FriendshipStatus::Pending]))
        ->toThrow(InvalidArgumentException::class);
});

// ---------------------------------------------------------------------------
// Search & requests
// ---------------------------------------------------------------------------

test('search finds an active user by exact email, case-insensitively, and never yourself', function () {
    $me     = User::factory()->create(['email' => 'me@example.com']);
    $friend = User::factory()->create(['email' => 'friend@example.com', 'name' => 'Frieda']);

    friendsPage($me)->set('searchEmail', 'FRIEND@example.com')->assertSee('Frieda');
    friendsPage($me)->set('searchEmail', 'friend@')->assertDontSee('Frieda');
    friendsPage($me)->set('searchEmail', 'me@example.com')->assertSet('searchResult', null);
});

test('search does not surface pending or suspended users', function () {
    $me = User::factory()->create();
    User::factory()->create(['email' => 'pending@example.com', 'name' => 'Pendy', 'status' => UserStatus::Pending]);

    friendsPage($me)->set('searchEmail', 'pending@example.com')->assertDontSee('Pendy');
});

test('sending a request creates a pending friendship with the sender as requester', function () {
    [$me, $them] = User::factory()->count(2)->create();

    friendsPage($me)->set('searchEmail', $them->email)->call('sendRequest');

    $f = Friendship::sole();
    expect($f->requester_id)->toBe($me->id)
        ->and($f->recipient_id)->toBe($them->id)
        ->and($f->status)->toBe(FriendshipStatus::Pending);
});

test('sending a request to someone who already asked you accepts theirs', function () {
    [$me, $them] = User::factory()->count(2)->create();
    Friendship::factory()->create(['requester_id' => $them->id, 'recipient_id' => $me->id]);

    friendsPage($me)->set('searchEmail', $them->email)->call('sendRequest');

    expect(Friendship::count())->toBe(1)
        ->and(Friendship::sole()->status)->toBe(FriendshipStatus::Accepted);
});

test('a declined request can be sent again', function () {
    [$me, $them] = User::factory()->count(2)->create();
    Friendship::factory()->create(['requester_id' => $them->id, 'recipient_id' => $me->id, 'status' => FriendshipStatus::Declined]);

    friendsPage($me)->set('searchEmail', $them->email)->call('sendRequest');

    $f = Friendship::sole();
    expect($f->status)->toBe(FriendshipStatus::Pending)
        ->and($f->requester_id)->toBe($me->id);
});

test('cannot send a request to an inactive user', function () {
    $me   = User::factory()->create();
    $them = User::factory()->create(['status' => UserStatus::Suspended]);

    friendsPage($me)->set('searchEmail', $them->email)->call('sendRequest');

    expect(Friendship::count())->toBe(0);
});

test('a request can only go to the exact-email search result, not an arbitrary user id', function () {
    // Found in the security review: taking a user id from the client let a
    // console loop over ids reveal every active user's name.
    [$me, $target, $other] = User::factory()->count(3)->create();

    friendsPage($me)->call('sendRequest', $target->id);
    friendsPage($me)->set('searchEmail', $other->email)->call('sendRequest', $target->id);

    expect(Friendship::count())->toBe(1)
        ->and(Friendship::sole()->recipient_id)->toBe($other->id);
});

// ---------------------------------------------------------------------------
// Accept / decline / cancel / remove — ownership of the row
// ---------------------------------------------------------------------------

test('the recipient can accept a request', function () {
    [$them, $me] = User::factory()->count(2)->create();
    $f = Friendship::factory()->create(['requester_id' => $them->id, 'recipient_id' => $me->id]);

    friendsPage($me)->call('accept', $f->id);

    expect($f->fresh()->status)->toBe(FriendshipStatus::Accepted)
        ->and($me->isFriendsWith($them))->toBeTrue()
        ->and($them->isFriendsWith($me))->toBeTrue();
});

test('the requester cannot accept their own request', function () {
    [$me, $them] = User::factory()->count(2)->create();
    $f = Friendship::factory()->create(['requester_id' => $me->id, 'recipient_id' => $them->id]);

    friendsPage($me)->call('accept', $f->id)->assertNotFound();

    expect($f->fresh()->status)->toBe(FriendshipStatus::Pending);
});

test('a third party cannot accept or decline someone else request', function () {
    [$a, $b, $outsider] = User::factory()->count(3)->create();
    $f = Friendship::factory()->create(['requester_id' => $a->id, 'recipient_id' => $b->id]);

    friendsPage($outsider)->call('accept', $f->id)->assertNotFound();
    friendsPage($outsider)->call('decline', $f->id)->assertNotFound();

    expect($f->fresh()->status)->toBe(FriendshipStatus::Pending);
});

test('the recipient can decline a request', function () {
    [$them, $me] = User::factory()->count(2)->create();
    $f = Friendship::factory()->create(['requester_id' => $them->id, 'recipient_id' => $me->id]);

    friendsPage($me)->call('decline', $f->id);

    expect($f->fresh()->status)->toBe(FriendshipStatus::Declined)
        ->and($me->isFriendsWith($them))->toBeFalse();
});

test('the requester can cancel a pending request', function () {
    [$me, $them] = User::factory()->count(2)->create();
    $f = Friendship::factory()->create(['requester_id' => $me->id, 'recipient_id' => $them->id]);

    friendsPage($me)->call('cancel', $f->id);

    expect(Friendship::count())->toBe(0);
});

test('either friend can remove the friendship, but an outsider cannot', function () {
    [$a, $b, $outsider] = User::factory()->count(3)->create();
    $f = Friendship::factory()->accepted()->create(['requester_id' => $a->id, 'recipient_id' => $b->id]);

    friendsPage($outsider)->call('remove', $f->id)->assertNotFound();
    expect(Friendship::count())->toBe(1);

    friendsPage($b)->call('remove', $f->id);
    expect(Friendship::count())->toBe(0);
});

test('the page lists incoming, outgoing and accepted friends', function () {
    [$me, $asker, $asked, $friend] = User::factory()->count(4)->sequence(
        ['name' => 'Me'], ['name' => 'Asker Ann'], ['name' => 'Asked Al'], ['name' => 'Friend Fay'],
    )->create();
    Friendship::factory()->create(['requester_id' => $asker->id, 'recipient_id' => $me->id]);
    Friendship::factory()->create(['requester_id' => $me->id, 'recipient_id' => $asked->id]);
    Friendship::factory()->accepted()->create(['requester_id' => $friend->id, 'recipient_id' => $me->id]);

    friendsPage($me)->assertSee('Asker Ann')->assertSee('Asked Al')->assertSee('Friend Fay');
});

// ---------------------------------------------------------------------------
// Friend request email
// ---------------------------------------------------------------------------

test('sending a friend request emails the recipient, not the sender', function () {
    [$me, $them] = User::factory()->count(2)->sequence(['name' => 'Sender Sam'], ['name' => 'Recipient Rae'])->create();
    Mail::fake();

    friendsPage($me)->set('searchEmail', $them->email)->call('sendRequest');

    Mail::assertSent(FriendRequestMail::class, 1);
    Mail::assertSent(FriendRequestMail::class, fn ($m) => $m->hasTo($them->email) && ! $m->hasTo($me->email));
});

test('the friend request email names the sender and links to the friends page', function () {
    [$me, $them] = User::factory()->count(2)->sequence(['name' => 'Sender Sam'], ['name' => 'Recipient Rae'])->create();
    $f = Friendship::factory()->create(['requester_id' => $me->id, 'recipient_id' => $them->id]);

    $mail = new FriendRequestMail($f);

    expect($mail->envelope()->subject)->toContain('Sender Sam');
    expect($mail->render())->toContain('Recipient Rae')->toContain(route('friends.index'));
});

test('re-requesting after a decline emails again', function () {
    [$me, $them] = User::factory()->count(2)->create();
    Friendship::factory()->create(['requester_id' => $them->id, 'recipient_id' => $me->id, 'status' => FriendshipStatus::Declined]);
    Mail::fake();

    friendsPage($me)->set('searchEmail', $them->email)->call('sendRequest');

    Mail::assertSent(FriendRequestMail::class, fn ($m) => $m->hasTo($them->email));
});

test('no request email when sending back accepts their request, or when already pending', function () {
    [$me, $them, $other] = User::factory()->count(3)->create();
    Friendship::factory()->create(['requester_id' => $them->id, 'recipient_id' => $me->id]);
    Friendship::factory()->create(['requester_id' => $me->id, 'recipient_id' => $other->id]);
    Mail::fake();

    friendsPage($me)->set('searchEmail', $them->email)->call('sendRequest');
    friendsPage($me)->set('searchEmail', $other->email)->call('sendRequest');

    Mail::assertNotSent(FriendRequestMail::class);
});
