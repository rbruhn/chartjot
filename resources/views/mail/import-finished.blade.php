{{--
    CSV import finished (ImportFinishedMail). Counts, guidance and — for an
    outright failure — the whole-file reason only. Never row data: failure
    details go in the attached CSV.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $importName }}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #111827; line-height: 1.5; margin: 0; padding: 0; background: #f9fafb; }
        .wrapper { max-width: 600px; margin: 2rem auto; background: #ffffff; border-radius: 8px; overflow: hidden; border: 1px solid #e5e7eb; }
        .header { background: #111827; color: #f9fafb; padding: 1.5rem 2rem; }
        .header h1 { margin: 0; font-size: 1.25rem; }
        .body { padding: 1.5rem 2rem; }
        p { margin: 0 0 1rem; }
        .reason { padding: 0.75rem 1rem; background: #fef2f2; border-left: 3px solid #dc2626; color: #7f1d1d; }
        .footer { padding: 1rem 2rem; font-size: 0.75rem; color: #9ca3af; border-top: 1px solid #f3f4f6; }
    </style>
</head>
<body>
@php
    $count = count($failures);
    $what  = $kind === \App\Mail\ImportFinishedMail::KIND_MAE_MFE ? 'Trades export' : 'Executions export';
@endphp
<div class="wrapper">
    <div class="header">
        <h1>{{ $journalName }} — {{ $importName }}</h1>
    </div>
    <div class="body">
        @if ($outcome === \App\Mail\ImportFinishedMail::OUTCOME_SUCCEEDED)
            <p>Your {{ $what }} upload has finished importing.</p>
            <p>{{ $summary }}</p>

        @elseif ($outcome === \App\Mail\ImportFinishedMail::OUTCOME_COMPLETED_WITH_FAILURES)
            <p>Your {{ $what }} upload has finished importing, but {{ $count }} {{ Str::plural('row', $count) }} could not be imported.</p>
            <p>{{ $summary }}</p>
            <p>
                A CSV of all failures is attached (account name, trade ID, reason, and time for each).
                @if ($kind === \App\Mail\ImportFinishedMail::KIND_MAE_MFE)
                    Most often the trade hasn't been imported from the Executions export yet, or the Trades export
                    only covers part of a trade — import the matching Executions export, or re-export the Trades grid
                    for the full range, then upload the Trades file again.
                @else
                    Most often an account name in the file doesn't match an account in your journal — create it on your
                    <strong>Accounts</strong> page, then import the file again. Trades already imported are skipped.
                @endif
                Rows that did import are already in your journal.
            </p>

        @else
            <p>Your {{ $what }} upload failed — nothing was imported. The reason:</p>
            <p class="reason">{{ $failureReason }}</p>
            <p>
                Check that you uploaded the NinjaTrader Trade Performance → <strong>{{ $kind === \App\Mail\ImportFinishedMail::KIND_MAE_MFE ? 'Trades' : 'Executions' }}</strong>
                export, and upload it again.
            </p>
        @endif
    </div>
    <div class="footer">
        Chart Jot · You are receiving this because you uploaded a CSV import to your journal.
    </div>
</div>
</body>
</html>
