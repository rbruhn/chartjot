<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Self-hosted mode (issue #102): signs every browser request in as the
 * journal's owner, so there is no login. Runs in the web group after the
 * session starts; does nothing when self-hosted mode is off.
 */
class SignInSelfHostedOwner
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('chartjot.self_hosted') && ! Auth::guard('web')->check()) {
            Auth::guard('web')->login($this->owner(), remember: true);
        }

        return $next($request);
    }

    /**
     * The first active user, so a journal switched over from hosted mode
     * keeps its trades. A fresh install creates one; its observer creates
     * the journal. createOrFirst covers two first requests racing.
     */
    private function owner(): User
    {
        return User::where('status', UserStatus::Active)->orderBy('id')->first()
            ?? User::createOrFirst(
                ['email' => config('chartjot.owner.email')],
                [
                    'name'     => config('chartjot.owner.name'),
                    'password' => Str::random(64),
                    'status'   => UserStatus::Active,
                    'is_admin' => false,
                ],
            );
    }
}
