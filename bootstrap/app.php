<?php

use App\Http\Middleware\AuthenticateJournalToken;
use App\Http\Middleware\EnsureMultiUser;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\SignInSelfHostedOwner;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'active'         => EnsureUserIsActive::class,
            'admin'          => EnsureUserIsAdmin::class,
            'journal.token'  => AuthenticateJournalToken::class,
            'multi-user'     => EnsureMultiUser::class,
        ]);

        // Self-hosted mode (issue #102): no login, every browser request is
        // signed in as the owner. It needs the session, and has to run before
        // `auth` so that never redirects to the (disabled) login page.
        $middleware->web(append: [SignInSelfHostedOwner::class]);
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: SignInSelfHostedOwner::class,
        );

        // Resolve the journal token before route-model binding, so an
        // unauthenticated request never touches the {trade} lookup. Throttle
        // already sorts ahead of SubstituteBindings, so the API runs
        // throttle -> journal.token -> bindings.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: AuthenticateJournalToken::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
