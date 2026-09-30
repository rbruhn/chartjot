<?php

namespace App\Jobs;

use App\Jobs\Concerns\EmailsImportOutcome;
use App\Mail\ImportFinishedMail;
use App\Models\Journal;
use App\Services\ExecutionsCsvImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class ImportExecutionsCsv implements ShouldQueue
{
    use EmailsImportOutcome, Queueable;

    public int $tries   = 1;
    public int $timeout = 120;

    public function __construct(
        private readonly Journal $journal,
        private readonly string  $filePath,
        private readonly string  $importId,
    ) {}

    public function handle(ExecutionsCsvImporter $importer): void
    {
        try {
            $result = $importer->import($this->journal, $this->filePath);

            Cache::put("csv_import_{$this->importId}", [
                'status' => 'complete',
                'result' => $result,
            ], now()->addHour());

            $mail = ImportFinishedMail::forResult(ImportFinishedMail::KIND_EXECUTIONS, $this->journal->name, $result);
        } catch (\Throwable $e) {
            Cache::put("csv_import_{$this->importId}", [
                'status' => 'failed',
                'error'  => $e->getMessage(),
            ], now()->addHour());

            $mail = ImportFinishedMail::forException(ImportFinishedMail::KIND_EXECUTIONS, $this->journal->name, $e->getMessage());
        } finally {
            if (file_exists($this->filePath)) {
                @unlink($this->filePath);
            }
        }

        $this->emailImportOutcome($this->journal, $mail);
    }
}
