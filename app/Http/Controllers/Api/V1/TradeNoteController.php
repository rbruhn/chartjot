<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TradeNoteRequest;
use App\Models\Journal;
use App\Models\Trade;
use App\Models\TradeNote;
use Illuminate\Http\JsonResponse;
use App\Enums\NotePhase;

class TradeNoteController extends Controller
{
    public function store(TradeNoteRequest $request, Trade $trade): JsonResponse
    {
        /** @var Journal $journal */
        $journal = $request->attributes->get('journal');

        if ($trade->journal_id !== $journal->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $note = TradeNote::create([
            'trade_id'    => $trade->id,
            'created_by'  => $journal->user_id,
            'body'        => $request->input('body'),
            'phase'       => NotePhase::from($request->input('phase')),
            'occurred_at' => $request->input('occurred_at'),
        ]);

        return response()->json([
            'data' => [
                'id'          => $note->id,
                'body'        => $note->body,
                'phase'       => $note->phase->value,
                'occurred_at' => $note->occurred_at->toIso8601String(),
            ],
        ], 201);
    }
}
