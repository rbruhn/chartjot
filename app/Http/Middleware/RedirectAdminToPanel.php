<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admins have no journal of their own — send them to the admin panel instead
 * of letting them fall through to a page that expects $user->journal to exist.
 */
class RedirectAdminToPanel
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->is_admin) {
            return redirect()->route('admin.users');
        }

        return $next($request);
    }
}
