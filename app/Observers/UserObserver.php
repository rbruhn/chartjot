<?php

namespace App\Observers;

use App\Mail\NewUserRegistered;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;

class UserObserver
{
    public function created(User $user): void
    {
        if ($user->is_admin) {
            return;
        }

        $token = Journal::createForUser($user);

        if (app()->bound('session')) {
            Session::flash('journal_ingest_token', $token);
        }

        $admins = User::where('is_admin', true)->get();
        if ($admins->isNotEmpty()) {
            Mail::to($admins)->send(new NewUserRegistered($user));
        }
    }

    public function updated(User $user): void {}

    public function deleted(User $user): void {}

    public function restored(User $user): void {}

    public function forceDeleted(User $user): void {}
}
