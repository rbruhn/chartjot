<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\UnknownAccountException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TradeIntakeRequest;
use App\Mail\FailedImportsMail;
use App\Models\FailedTradeImport;
use App\Models\Journal;
use App\Models\Trade;
use App\Services\TradeIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class TradeIntakeController extends Controller
{
    public function store(TradeIntakeRequest $request, TradeIntakeService $service): JsonResponse
    {
        /** @var Journal $journal */
        $journal = $request->attributes->get('journal');

        if (blank($journal->timezone)) {
            return response()->json([
                'message' => 'Journal timezone is not set. Configure it in Journal Settings before sending trades.',
                'errors'  => ['timezone' => ['Journal timezone must be set before importing trades.']],
            ], 422);
        }

        $tradeId       = $request->input('trade_id');
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

        try {
            $trade = $service->store(
                $journal,
                $request->validated(),
                $request->file('screenshot_file'),
                $request->file('entry_screenshot_file')
            );
        } catch (UnknownAccountException $e) {
            $failure = [
                'account_name'    => $e->accountName,
                'source_trade_id' => $request->input('trade_id'),
                'reason'          => $e->getMessage(),
                'occurred_at'     => now()->toDateTimeString(),
            ];

            FailedTradeImport::create([
                'journal_id'      => $journal->id,
                'account_name'    => $failure['account_name'],
                'source_trade_id' => $failure['source_trade_id'],
                'reason'          => $failure['reason'],
                'payload'         => $request->except(['screenshot_file', 'entry_screenshot_file']),
                'occurred_at'     => now(),
            ]);

            Mail::to($journal->user()->value('email'))
                ->send(new FailedImportsMail($journal->name, [$failure]));

            return response()->json([
                'message' => $e->getMessage(),
                'errors'  => ['account_name' => [$e->getMessage()]],
            ], 422);
        }

        return $this->tradeResponse($trade, 201);
    }

    private function tradeResponse(Trade $trade, int $status): JsonResponse
    {
        return response()->json([
            'data' => [
                'id'          => $trade->uuid,
                'status'      => $status === 201 ? 'accepted' : 'duplicate',
                'received_at' => $trade->created_at->toIso8601String(),
                'trade_url'   => url("/journal/trades/{$trade->uuid}"),
            ],
        ], $status);
    }
}
