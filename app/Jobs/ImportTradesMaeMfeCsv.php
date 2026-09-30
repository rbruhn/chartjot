<?php

namespace App\Jobs;

use App\Models\Journal;
use App\Services\TradesMaeMfeImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/** Same shape as ImportExecutionsCsv, for NT8's Trades export (leg-level MAE/MFE). */
class ImportTradesMaeMfeCsv implements ShouldQueue
{
    use Queueable;

    public int $tries   = 1;
    public int $timeout = 120;

    public function __construct(
        private readonly Journal $journal,
        private readonly string  $filePath,
        private readonly string  $importId,
    ) {}

    public function handle(TradesMaeMfeImporter $importer): void
    {
        try {
            $result = $importer->import($this->journal, $this->filePath);

            Cache::put("mae_mfe_import_{$this->importId}", [
                'status' => 'complete',
                'result' => $result,
            ], now()->addHour());
        } catch (\Throwable $e) {
            Cache::put("mae_mfe_import_{$this->importId}", [
                'status' => 'failed',
                'error'  => $e->getMessage(),
            ], now()->addHour());
        } finally {
            if (file_exists($this->filePath)) {
                @unlink($this->filePath);
            }
        }
    }
}
