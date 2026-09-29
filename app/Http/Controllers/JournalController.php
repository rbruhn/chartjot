<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class JournalController extends Controller
{
    public function index(Request $request): View
    {
        $journal = $request->user()->journal;

        $this->authorize('view', $journal);

        return view('journal.index', [
            'journal' => $journal,
        ]);
    }
}
