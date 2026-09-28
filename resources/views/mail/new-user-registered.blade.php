<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #374151; background: #f9fafb; margin: 0; padding: 32px 16px;">
    <div style="max-width: 520px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 32px; border: 1px solid #e5e7eb;">
        <h1 style="font-size: 18px; font-weight: 600; margin: 0 0 8px;">New User Registration</h1>
        <p style="color: #6b7280; margin: 0 0 24px; font-size: 14px;">A new user is awaiting your approval.</p>

        <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
            <tr>
                <td style="padding: 8px 0; color: #6b7280; width: 100px;">Name</td>
                <td style="padding: 8px 0; font-weight: 500;">{{ $user->name }}</td>
            </tr>
            <tr>
                <td style="padding: 8px 0; color: #6b7280;">Email</td>
                <td style="padding: 8px 0; font-weight: 500;">{{ $user->email }}</td>
            </tr>
            <tr>
                <td style="padding: 8px 0; color: #6b7280;">Registered</td>
                <td style="padding: 8px 0;">{{ $user->created_at->toDayDateTimeString() }}</td>
            </tr>
        </table>

        <div style="margin-top: 28px;">
            <a href="{{ route('admin.users') }}"
               style="display: inline-block; background: #4f46e5; color: #fff; text-decoration: none; padding: 10px 20px; border-radius: 6px; font-size: 14px; font-weight: 500;">
                Review in Admin Dashboard
            </a>
        </div>

        <p style="margin-top: 24px; font-size: 12px; color: #9ca3af;">
            {{ config('app.name') }}
        </p>
    </div>
</body>
</html>
