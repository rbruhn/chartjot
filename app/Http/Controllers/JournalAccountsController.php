<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class JournalAccountsController extends Controller
{
    public function index(Request $request): View
    {
        $user    = $request->user();
        $journal = $user->journal;

        $this->authorize('view', $journal);

        return view('journal.accounts', ['journal' => $journal]);
    }
}
