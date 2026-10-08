<?php

namespace App\Console\Commands;

use App\Models\Journal;
use App\Models\User;
use App\Support\JournalArchive;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

#[Signature('journal:import
    {file : The zip written by journal:export}
    {--email= : Email of the user whose journal gets the trades (needed when there are several users)}')]
#[Description('Import a journal exported with journal:export')]
class ImportJournalCommand extends Command
{
    /** @var array<string, list<string>> */
    private array $columns = [];

    /** @var list<string> Images written so far, removed again if the import fails. */
    private array $writtenImages = [];

    public function handle(): int
    {
        if (! class_exists(ZipArchive::class)) {
            $this->error('PHP\'s zip extension is not installed, so the zip can\'t be read.');

            return self::FAILURE;
        }

        $zip = new ZipArchive;
        if ($zip->open($this->argument('file'), ZipArchive::RDONLY) !== true) {
            $this->error('Couldn\'t open that zip.');

            return self::FAILURE;
        }

        $data = json_decode((string) $zip->getFromName(JournalArchive::DATA_FILE), true);
        if (! is_array($data) || ($data['format'] ?? null) !== JournalArchive::FORMAT) {
            $this->error('That zip isn\'t a Chart Jot journal export this version can read.');

            return self::FAILURE;
        }

        $journal = $this->journal();
        if (! $journal) {
            return self::FAILURE;
        }

        try {
            $counts = DB::transaction(fn () => $this->import($journal, $data, $zip));
        } catch (\Throwable $e) {
            foreach ($this->writtenImages as $path) {
                Storage::disk('local')->delete($path);
            }

            throw $e;
        } finally {
            $zip->close();
        }

        $this->table(['What', 'Imported', 'Already there'], [
            ['Accounts', $counts['accounts'], $counts['accounts_existing']],
            ['Deposits and withdrawals', $counts['account_transactions'], ''],
            ['Trades', $counts['trades'], $counts['trades_existing']],
            ['Notes', $counts['trade_notes'], ''],
            ['Images', $counts['trade_screenshots'], ''],
        ]);
        $this->info("Imported into {$journal->user->email}'s journal.");

        return self::SUCCESS;
    }

    /** The journal to import into: --email's, or the only user's. */
    private function journal(): ?Journal
    {
        $email = $this->option('email');
        if (! $email && User::count() !== 1) {
            $this->error(User::count() === 0
                ? 'There are no users yet. Open the journal in your browser once, then run this again.'
                : 'There are several users; pick one with --email.');

            return null;
        }

        $user = User::with('journal')->when($email, fn ($q) => $q->where('email', $email))->first();
        if (! $user?->journal) {
            $this->error('No user with a journal found for that email.');

            return null;
        }

        return $user->journal->setRelation('user', $user);
    }

    /**
     * Writes the rows, parents first, renumbering every id and link for this
     * journal. An account that already exists (same name) is reused without
     * its deposits and withdrawals; a trade that already exists (same uuid,
     * or same source trade id in this journal) is skipped with everything
     * under it.
     *
     * @param  array{journal: array{timezone: ?string}, tables: array<string, list<array<string, mixed>>>}  $data
     * @return array<string, int>
     */
    private function import(Journal $journal, array $data, ZipArchive $zip): array
    {
        $tables = $data['tables'];
        $counts = array_fill_keys(['accounts', 'accounts_existing', 'account_transactions', 'trades',
            'trades_existing', 'trade_notes', 'trade_screenshots'], 0);

        if (blank($journal->timezone) && filled($data['journal']['timezone'] ?? null)) {
            $journal->update(['timezone' => $data['journal']['timezone']]);
        }

        $accountIds = [];
        $newAccounts = [];
        foreach ($tables['accounts'] as $row) {
            $existing = DB::table('accounts')->where('journal_id', $journal->id)->where('name', $row['name'])->value('id');
            if ($existing) {
                $accountIds[$row['id']] = $existing;
                $counts['accounts_existing']++;

                continue;
            }

            $accountIds[$row['id']] = $this->insert('accounts', $row, ['journal_id' => $journal->id]);
            $newAccounts[$row['id']] = true;
            $counts['accounts']++;
        }

        foreach ($tables['account_transactions'] as $row) {
            if (isset($newAccounts[$row['account_id']])) {
                $this->insert('account_transactions', $row, ['account_id' => $accountIds[$row['account_id']]]);
                $counts['account_transactions']++;
            }
        }

        $tradeIds = [];
        $newTrades = [];
        foreach ($tables['trades'] as $row) {
            $existing = DB::table('trades')->where('uuid', $row['uuid'])->value('id')
                ?? DB::table('trades')->where('journal_id', $journal->id)->where('source_trade_id', $row['source_trade_id'])->value('id');
            if ($existing) {
                $tradeIds[$row['id']] = $existing;
                $counts['trades_existing']++;

                continue;
            }

            // Masters are linked below, once every trade has its new id.
            $tradeIds[$row['id']] = $this->insert('trades', $row, [
                'journal_id' => $journal->id,
                'account_id' => $accountIds[$row['account_id']],
                'master_trade_id' => null,
            ]);
            $newTrades[$row['id']] = true;
            $counts['trades']++;
        }

        foreach ($tables['trades'] as $row) {
            if (isset($newTrades[$row['id']], $row['master_trade_id'], $tradeIds[$row['master_trade_id']])) {
                DB::table('trades')->where('id', $tradeIds[$row['id']])
                    ->update(['master_trade_id' => $tradeIds[$row['master_trade_id']]]);
            }
        }

        foreach (['trade_executions', 'trade_legs', 'trade_notes'] as $table) {
            foreach ($tables[$table] as $row) {
                if (! isset($newTrades[$row['trade_id']])) {
                    continue;
                }

                $links = ['trade_id' => $tradeIds[$row['trade_id']]];
                if ($table === 'trade_notes') {
                    $links['created_by'] = $journal->user_id;
                    $counts['trade_notes']++;
                }
                $this->insert($table, $row, $links);
            }
        }

        foreach ($tables['trade_screenshots'] as $row) {
            if (! isset($newTrades[$row['trade_id']])) {
                continue;
            }

            $contents = $zip->getFromName($row['file']);
            if ($contents === false) {
                $this->warn("Image missing from the zip, left out: {$row['file']}");

                continue;
            }

            $path = "trade-screenshots/{$journal->id}/".basename($row['path']);
            Storage::disk('local')->put($path, $contents);
            $this->writtenImages[] = $path;

            $this->insert('trade_screenshots', $row, [
                'trade_id' => $tradeIds[$row['trade_id']],
                'disk' => 'local',
                'path' => $path,
            ]);
            $counts['trade_screenshots']++;
        }

        return $counts;
    }

    /**
     * Inserts an exported row with its links replaced, keeping only columns
     * this database has (the export may come from an older or newer copy).
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $links
     */
    private function insert(string $table, array $row, array $links): int
    {
        $this->columns[$table] ??= Schema::getColumnListing($table);

        $values = array_intersect_key(array_merge($row, $links), array_flip($this->columns[$table]));
        unset($values['id']);

        return DB::table($table)->insertGetId($values);
    }
}
