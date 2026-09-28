<?php

namespace App\Http\Controllers;

use App\Models\Trade;
use App\Models\TradeScreenshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TradeScreenshotController extends Controller
{
    public function show(Request $request, Trade $trade, TradeScreenshot $screenshot): StreamedResponse
    {
        $journal = $request->user()->journal;

        abort_if($trade->journal_id !== $journal->id, 404);
        abort_if($screenshot->trade_id !== $trade->id, 404);
        abort_unless(Storage::disk($screenshot->disk)->exists($screenshot->path), 404);

        return Storage::disk($screenshot->disk)->response($screenshot->path, null, [
            'Content-Type' => $screenshot->mime_type,
        ]);
    }
}
