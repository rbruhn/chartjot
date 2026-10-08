<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\JournalArchive;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

#[Signature('journal:export
    {user : Id or email of the user whose journal to export}
    {--path= : Folder to write the zip to (default storage/app/exports)}')]
#[Description('Export a journal (accounts, trades, notes, chart images) to a zip for journal:import')]
class ExportJournalCommand extends Command
{
    public function handle(): int
    {
        if (! class_exists(ZipArchive::class)) {
            $this->error('PHP\'s zip extension is not installed, so the zip can\'t be written.');

            return self::FAILURE;
        }

        $user = User::with('journal')
            ->where(is_numeric($this->argument('user')) ? 'id' : 'email', $this->argument('user'))
            ->first();
        if (! $user?->journal) {
            $this->error('No user with a journal found for that id or email.');

            return self::FAILURE;
        }

        $journal = $user->journal;
        $tables = $this->rows($journal->id);

        $folder = $this->option('path') ?: storage_path('app/exports');
        File::ensureDirectoryExists($folder);
        $zipPath = rtrim($folder, '/').'/journal-'.$user->id.'-'.now()->format('Y-m-d-His').'.zip';

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error("Couldn't create {$zipPath}.");

            return self::FAILURE;
        }

        $screenshots = [];
        $missing = 0;
        foreach ($tables['trade_screenshots'] as $screenshot) {
            $disk = Storage::disk($screenshot['disk']);
            if (! $disk->exists($screenshot['path'])) {
                $this->warn("Image missing, left out: {$screenshot['path']}");
                $missing++;

                continue;
            }

            $file = JournalArchive::IMAGES_DIR.$screenshot['id'].'-'.basename($screenshot['path']);
            $zip->addFromString($file, $disk->get($screenshot['path']));
            $screenshots[] = $screenshot + ['file' => $file];
        }
        $tables['trade_screenshots'] = $screenshots;

        $zip->addFromString(JournalArchive::DATA_FILE, json_encode([
            'format' => JournalArchive::FORMAT,
            'exported_at' => now()->toIso8601String(),
            'journal' => ['name' => $journal->name, 'timezone' => $journal->timezone],
            'tables' => $tables,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $zip->close();

        $this->table(['What', 'Count'], [
            ['Accounts', count($tables['accounts'])],
            ['Deposits and withdrawals', count($tables['account_transactions'])],
            ['Trades', count($tables['trades'])],
            ['Notes', count($tables['trade_notes'])],
            ['Images', count($tables['trade_screenshots'])],
        ]);
        if ($missing > 0) {
            $this->warn("{$missing} image(s) were missing on disk and left out.");
        }
        $this->info("Exported to {$zipPath}");

        return self::SUCCESS;
    }

    /**
     * The journal's rows in every exported table, as stored. Friends'
     * comments, invitations, failed AddOn imports and the intake token
     * are left out.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function rows(int $journalId): array
    {
        $accountIds = fn (Builder $q) => $q->select('id')->from('accounts')->where('journal_id', $journalId);
        $tradeIds = fn (Builder $q) => $q->select('id')->from('trades')->where('journal_id', $journalId);

        $rows = fn (string $table, string $column, $ids) => DB::table($table)
            ->whereIn($column, $ids)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row) => (array) $row)
            ->all();

        return [
            'accounts' => $rows('accounts', 'journal_id', [$journalId]),
            'account_transactions' => $rows('account_transactions', 'account_id', $accountIds),
            'trades' => $rows('trades', 'journal_id', [$journalId]),
            'trade_executions' => $rows('trade_executions', 'trade_id', $tradeIds),
            'trade_legs' => $rows('trade_legs', 'trade_id', $tradeIds),
            'trade_notes' => $rows('trade_notes', 'trade_id', $tradeIds),
            'trade_screenshots' => $rows('trade_screenshots', 'trade_id', $tradeIds),
        ];
    }
}
