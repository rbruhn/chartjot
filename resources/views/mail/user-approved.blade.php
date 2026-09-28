<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #374151; background: #f9fafb; margin: 0; padding: 32px 16px;">
    <div style="max-width: 520px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 32px; border: 1px solid #e5e7eb;">
        <h1 style="font-size: 18px; font-weight: 600; margin: 0 0 8px;">Your Account Has Been Approved</h1>
        <p style="color: #374151; margin: 0 0 16px;">Hi {{ $user->name }},</p>
        <p style="color: #374151; margin: 0 0 24px;">
            Your Chart Jot account has been approved. You can now sign in and start logging your trades.
        </p>

        <div style="margin-top: 8px;">
            <a href="{{ route('login') }}"
               style="display: inline-block; background: #4f46e5; color: #fff; text-decoration: none; padding: 10px 20px; border-radius: 6px; font-size: 14px; font-weight: 500;">
                Sign In to Your Journal
            </a>
        </div>

        <p style="margin-top: 24px; font-size: 12px; color: #9ca3af;">
            {{ config('app.name') }}
        </p>
    </div>
</body>
</html>
