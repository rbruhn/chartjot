<?php

namespace App\Http\Controllers;

use App\Models\Trade;
use App\Services\TradeCommentPoster;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TradeCommentController extends Controller
{
    /** Authorization, validation, rate limiting and email all live in TradeCommentPoster. */
    public function store(Request $request, Trade $trade, TradeCommentPoster $poster): RedirectResponse
    {
        $comment = $poster->post($trade, $request->user(), [
            ...$request->only(['body', 'parent_comment_id']),
            'image' => $request->file('image'),
        ]);

        return redirect()->to(route('trades.shared', $trade).'#comment-'.$comment->id);
    }
}
