<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** #115: "View the demo" signs a visitor in as the read-only demo account, without a password. */
class DemoController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $demo = User::where('is_demo', true)->first();
        abort_unless($demo, 404);

        Auth::guard('web')->login($demo);
        $request->session()->regenerate();

        return to_route('journal.index');
    }
}
