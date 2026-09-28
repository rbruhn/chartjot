<?php

namespace App\Http\Middleware;

use App\Models\Journal;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateJournalToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Candidate journals: no way to index on a hash, so we load all and check.
        // In practice there will be very few journals, so this is fine.
        $journal = Journal::lazy()->first(
            fn (Journal $j) => Hash::check($token, $j->ingest_token_hash)
        );

        if (! $journal) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $request->attributes->set('journal', $journal);

        return $next($request);
    }
}
