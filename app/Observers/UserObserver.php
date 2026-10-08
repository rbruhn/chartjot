<?php

namespace App\Observers;

use App\Mail\NewUserRegistered;
use App\Models\Journal;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;

class UserObserver
{
    public function created(User $user): void
    {
        $token = Journal::createForUser($user);

        if (app()->bound('session')) {
            Session::flash('journal_ingest_token', $token);
        }

        // Admins, the self-hosted owner (issue #102) and the demo account (#115)
        // aren't registrations anyone has to approve.
        if ($user->is_admin || $user->is_demo || config('chartjot.self_hosted')) {
            return;
        }

        $recipients = $this->registrationNotificationRecipients();
        if ($recipients->isNotEmpty()) {
            Mail::to($recipients)->send(new NewUserRegistered($user));
        }
    }

    /**
     * Who gets "new user registered" emails. A single configured address
     * (ADMIN_EMAIL) if set, so notifications don't fan out to every admin
     * account — falls back to all admins otherwise, so environments without
     * ADMIN_EMAIL configured (local/CI) keep the old behavior.
     */
    private function registrationNotificationRecipients(): Collection
    {
        $configured = config('mail.admin_notification_email');
        if ($configured) {
            return collect([$configured]);
        }

        return User::where('is_admin', true)->get();
    }

    /** #115: the demo account itself can't be changed, whoever is signed in (Profile signs out before deleting). */
    public function updating(User $user): void
    {
        if ($user->getOriginal('is_demo')) {
            Demo::ensureWritableUnlessAllowed();
        }
    }

    public function deleting(User $user): void
    {
        if ($user->getOriginal('is_demo')) {
            Demo::ensureWritableUnlessAllowed();
        }
    }

    public function updated(User $user): void {}

    public function deleted(User $user): void {}

    public function restored(User $user): void {}

    public function forceDeleted(User $user): void {}
}
