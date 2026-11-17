<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TradeIntakeRequest;
use App\Models\Journal;
use App\Models\Trade;
use App\Services\TradeIntakeService;
use Illuminate\Http\JsonResponse;

class TradeIntakeController extends Controller
{
    public function store(TradeIntakeRequest $request, TradeIntakeService $service): JsonResponse
    {
        /** @var Journal $journal */
        $journal = $request->attributes->get('journal');

        if (blank($journal->timezone)) {
            return response()->json([
                'message' => 'Journal timezone is not set. Configure it in Journal Settings before sending trades.',
                'errors' => ['timezone' => ['Journal timezone must be set before importing trades.']],
            ], 422);
        }

        $tradeId = $request->input('trade_id');
        $idempotencyKey = $request->header('Idempotency-Key');

        // Idempotency: return existing trade if already received
        $existing = Trade::where('journal_id', $journal->id)
            ->where(function ($q) use ($tradeId, $idempotencyKey) {
                $q->where('source_trade_id', $tradeId);
                if ($idempotencyKey && $idempotencyKey !== $tradeId) {
                    $q->orWhere('source_trade_id', $idempotencyKey);
                }
            })
            ->first();

        if ($existing) {
            return $this->tradeResponse($existing, 200);
        }

        $trade = $service->store(
            $journal,
            $request->validated(),
            $request->file('screenshot_file'),
            $request->file('entry_screenshot_file')
        );

        return $this->tradeResponse($trade, 201);
    }

    private function tradeResponse(Trade $trade, int $status): JsonResponse
    {
        return response()->json([
            'data' => [
                'id' => $trade->uuid,
                'status' => $status === 201 ? 'accepted' : 'duplicate',
                'received_at' => $trade->created_at->toIso8601String(),
                'trade_url' => url("/journal/trades/{$trade->uuid}"),
            ],
        ], $status);
    }
}
