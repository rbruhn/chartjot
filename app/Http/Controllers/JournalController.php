<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class JournalController extends Controller
{
    public function index(Request $request): View|\Illuminate\Http\RedirectResponse
    {
        $user = $request->user();

        if ($user->is_admin) {
            return redirect()->route('admin.users');
        }

        $journal = $user->journal;

        $this->authorize('view', $journal);

        return view('journal.index', [
            'journal' => $journal,
        ]);
    }
}
