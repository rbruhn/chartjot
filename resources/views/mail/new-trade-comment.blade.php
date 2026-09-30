@php
    // Only fields every participant may see (issue #18): no account data.
    $trade = $comment->trade;
    $summary = ucfirst($trade->direction->value).' '.$trade->quantity.' '.$trade->instrument;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #374151; background: #f9fafb; margin: 0; padding: 32px 16px;">
    <div style="max-width: 520px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 32px; border: 1px solid #e5e7eb;">
        <h1 style="font-size: 18px; font-weight: 600; margin: 0 0 8px;">New Comment on a Trade</h1>
        <p style="color: #374151; margin: 0 0 16px;">
            {{ $comment->author->name }} {{ $comment->parent_comment_id ? 'replied' : 'commented' }} on <strong>{{ $summary }}</strong>:
        </p>
        <blockquote style="margin: 0 0 24px; padding: 12px 16px; background: #f9fafb; border-left: 3px solid #4f46e5; color: #374151; white-space: pre-line;">{{ \Illuminate\Support\Str::limit($comment->body, 1000) }}</blockquote>

        <div style="margin-top: 8px;">
            <a href="{{ route('trades.shared', $trade) }}#comment-{{ $comment->id }}"
               style="display: inline-block; background: #4f46e5; color: #fff; text-decoration: none; padding: 10px 20px; border-radius: 6px; font-size: 14px; font-weight: 500;">
                View the Conversation
            </a>
        </div>

        <p style="margin-top: 24px; font-size: 12px; color: #9ca3af;">
            {{ config('app.name') }}
        </p>
    </div>
</body>
</html>
