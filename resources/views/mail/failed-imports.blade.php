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
        table { width: 100%; border-collapse: collapse; font-size: 0.875rem; margin: 1rem 0; }
        th { background: #f3f4f6; text-align: left; padding: 0.5rem 0.75rem; border-bottom: 2px solid #e5e7eb; font-weight: 600; color: #374151; }
        td { padding: 0.5rem 0.75rem; border-bottom: 1px solid #f3f4f6; color: #111827; }
        .reason { color: #dc2626; }
        .footer { padding: 1rem 2rem; font-size: 0.75rem; color: #9ca3af; border-top: 1px solid #f3f4f6; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="header">
        <h1>{{ $journalName }} — Failed Trade Imports</h1>
    </div>
    <div class="body">
        <p>
            {{ count($failures) }} trade{{ count($failures) === 1 ? '' : 's' }} could not be imported
            because the account name sent by NinjaTrader did not match any account in your journal.
        </p>
        <p>
            A CSV of all failures is attached. To resolve this, go to your
            <strong>Accounts</strong> page and create an account whose name exactly matches
            what NinjaTrader is sending, then re-export and re-import those trades.
        </p>
        <table>
            <thead>
                <tr>
                    <th>Account Name</th>
                    <th>Trade ID</th>
                    <th>Reason</th>
                    <th>Time</th>
                </tr>
            </thead>
            <tbody>
                @foreach($failures as $f)
                <tr>
                    <td>{{ $f['account_name'] }}</td>
                    <td>{{ $f['source_trade_id'] ?? '—' }}</td>
                    <td class="reason">{{ $f['reason'] }}</td>
                    <td>{{ $f['occurred_at'] }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="footer">
        Chart Jot · You are receiving this because a trade import failed for your journal.
    </div>
</div>
</body>
</html>
