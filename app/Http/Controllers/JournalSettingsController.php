<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateJournalSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class JournalSettingsController extends Controller
{
    public function edit(Request $request): View
    {
        $journal = $request->user()->journal;

        $this->authorize('view', $journal);

        return view('journal.settings', [
            'journal' => $journal,
            'ingestToken' => $request->session()->get('journal_ingest_token'),
        ]);
    }

    public function update(UpdateJournalSettingsRequest $request): RedirectResponse
    {
        $journal = $request->user()->journal;

        $journal->update($request->validated());

        return to_route('journal.settings.edit')->with('status', 'Journal settings saved.');
    }

    public function rotateToken(Request $request): RedirectResponse
    {
        $journal = $request->user()->journal;

        $this->authorize('update', $journal);

        $token = $journal->rotateIngestToken();

        return to_route('journal.settings.edit')->with([
            'status' => 'A new intake token was generated.',
            'journal_ingest_token' => $token,
        ]);
    }
}
