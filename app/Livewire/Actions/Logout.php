<?php

namespace App\Livewire\Actions;

use App\Support\Demo;
use App\Support\SelfHostedPassword;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Session;

class Logout
{
    /**
     * Log the current user out of the application.
     */
    public function __invoke(): void
    {
        // Signing out may rotate the remember token; the read-only demo (#115) must still be able to.
        Demo::allowingWrites(fn () => Auth::guard('web')->logout());

        Session::invalidate();
        Session::regenerateToken();

        // Self-hosted with a password (issue #102): logging out locks this browser.
        if (SelfHostedPassword::isRequired()) {
            Cookie::queue(SelfHostedPassword::forgetCookie());
        }
    }
}
