<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** #117: the registration page is 404 when CHARTJOT_REGISTRATION is off. */
class EnsureRegistrationIsOpen
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('chartjot.registration'), 404);

        return $next($request);
    }
}
