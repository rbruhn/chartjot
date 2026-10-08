<?php

namespace App\Http\Controllers;

use App\Support\SelfHostedPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The unlock page for self-hosted mode's optional password (issue #102).
 * Only exists when CHARTJOT_PASSWORD is set.
 */
class SelfHostedUnlockController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        abort_unless(SelfHostedPassword::isRequired(), 404);

        if (SelfHostedPassword::isUnlocked($request)) {
            return to_route('journal.index');
        }

        return view('self-hosted.unlock');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(SelfHostedPassword::isRequired(), 404);

        $request->validate(['password' => ['required', 'string']]);

        if (! SelfHostedPassword::matches((string) $request->input('password'))) {
            throw ValidationException::withMessages(['password' => 'That password is not correct.']);
        }

        return redirect()->intended(route('journal.index'))
            ->withCookie(SelfHostedPassword::unlockCookie());
    }
}
