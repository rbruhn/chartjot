<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the pages that only make sense with several users: registration,
 * login, admin, friends and trade sharing. In self-hosted mode (issue #102)
 * they don't exist, so they return 404.
 */
class EnsureMultiUser
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('chartjot.self_hosted')) {
            abort(404);
        }

        return $next($request);
    }
}
