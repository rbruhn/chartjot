<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Failed Trade Imports</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #111827; line-height: 1.5; margin: 0; padding: 0; background: #f9fafb; }
        .wrapper { max-width: 600px; margin: 2rem auto; background: #ffffff; border-radius: 8px; overflow: hidden; border: 1px solid #e5e7eb; }
        .header { background: #111827; color: #f9fafb; padding: 1.5rem 2rem; }
        .header h1 { margin: 0; font-size: 1.25rem; }
        .body { padding: 1.5rem 2rem; }
        p { margin: 0 0 1rem; }
        .footer { padding: 1rem 2rem; font-size: 0.75rem; color: #9ca3af; border-top: 1px solid #f3f4f6; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="header">
        <h1>{{ $journalName }} — {{ $kind === 'mae_mfe' ? 'MAE/MFE Import' : 'Failed Trade Imports' }}</h1>
    </div>
    <div class="body">
        {{-- Counts and guidance only: the failure details (accounts, trades, reasons) go in the attachment, never the body. --}}
        @if ($kind === 'mae_mfe')
            <p>
                {{ count($failures) }} row{{ count($failures) === 1 ? '' : 's' }} from your Trades export could not be matched
                to a trade in your journal, so no MAE/MFE was added for {{ count($failures) === 1 ? 'it' : 'them' }}.
            </p>
            <p>
                A CSV of all failures is attached (account name, trade ID, reason, and time for each). Most often
                the trade hasn't been imported from the Executions export yet, or the Trades export only covers part
                of a trade — import the matching Executions export, or re-export the Trades grid for the full range,
                then upload the Trades file again. Rows that were matched have already been imported.
            </p>
        @else
            <p>
                {{ count($failures) }} trade{{ count($failures) === 1 ? '' : 's' }} could not be imported
                because the account name sent by NinjaTrader did not match any account in your journal.
            </p>
            <p>
                A CSV of all failures is attached (account name, trade ID, reason, and time
                for each). To resolve this, go to your <strong>Accounts</strong> page and
                create an account whose name exactly matches what NinjaTrader is sending,
                then re-export and re-import those trades.
            </p>
        @endif
    </div>
    <div class="footer">
        Chart Jot · You are receiving this because a trade import failed for your journal.
    </div>
</div>
</body>
</html>
