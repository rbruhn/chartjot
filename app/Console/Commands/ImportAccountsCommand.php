<?php

namespace App\Console\Commands;

use App\Enums\AccountType;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

#[Signature('accounts:import
    {email : Email of the user whose journal gets the accounts}
    {file : CSV with header name,type,starting_balance,connection}
    {--current : Treat the CSV balance as today\'s balance and back out journaled P&L and transactions}
    {--dry-run : Show what would change without writing anything}')]
#[Description('Create or update journal accounts from a CSV')]
class ImportAccountsCommand extends Command
{
    private const HEADER = ['name', 'type', 'starting_balance', 'connection'];

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user?->journal) {
            $this->error('No user with a journal found for that email.');

            return self::FAILURE;
        }

        $rows = $this->readRows($this->argument('file'));
        if ($rows === null) {
            return self::FAILURE;
        }

        $plan = $this->plan($user->journal, $rows);
        if ($plan === null) {
            return self::FAILURE;
        }

        $this->table(
            ['Account', 'Action', 'Type', 'Trades', 'Starting balance', 'Balance shown'],
            array_map(fn (array $p) => [
                $p['name'],
                $p['account'] ? 'update' : 'create',
                $p['type']->label(),
                $p['trades'],
                $this->money($p['starting_balance']),
                $this->money($p['balance_shown']),
            ], $plan),
        );

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($user, $plan) {
            foreach ($plan as $p) {
                $data = [
                    'name' => $p['name'],
                    'account_type' => $p['type'],
                    'starting_balance' => $p['starting_balance'],
                    'connection' => $p['connection'],
                ];

                $p['account']
                    ? $p['account']->update($data)
                    : $user->journal->accounts()->create($data + ['timezone' => null]);
            }
        });

        $this->info(count($plan).' account(s) imported.');

        return self::SUCCESS;
    }

    /**
     * @return list<array{line: int, name: string, type: string, starting_balance: string, connection: string}>|null
     */
    private function readRows(string $file): ?array
    {
        if (! is_file($file) || ! is_readable($file)) {
            $this->error("Cannot read file: {$file}");

            return null;
        }

        $handle = fopen($file, 'r');
        $header = fgetcsv($handle, escape: '');
        $header = $header ? array_map(fn ($h) => strtolower(trim((string) $h)), $header) : [];
        // Tolerate a UTF-8 BOM from spreadsheet exports.
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        }

        if ($header !== self::HEADER) {
            fclose($handle);
            $this->error('Header must be: '.implode(',', self::HEADER));

            return null;
        }

        $rows = [];
        $errors = [];
        $line = 1;
        while (($fields = fgetcsv($handle, escape: '')) !== false) {
            $line++;
            if ($fields === [null]) {
                continue; // blank line
            }
            if (count($fields) !== count(self::HEADER)) {
                $errors[] = "Line {$line}: expected ".count(self::HEADER).' columns, got '.count($fields).'.';

                continue;
            }
            $rows[] = ['line' => $line] + array_combine(self::HEADER, array_map(fn ($f) => trim((string) $f), $fields));
        }
        fclose($handle);

        if ($errors) {
            $this->reportErrors($errors);

            return null;
        }

        if ($rows === []) {
            $this->error('The file has no account rows.');

            return null;
        }

        return $rows;
    }

    /**
     * Validate every row and work out what each one will do. Returns null
     * (after printing every error) if any row is invalid, so nothing is written.
     */
    private function plan(Journal $journal, array $rows): ?array
    {
        $existing = $journal->accounts()
            ->whereIn('name', array_column($rows, 'name'))
            ->withCount('trades')
            ->withSum('trades', 'net_pnl')
            ->withSum(['transactions as deposits_total' => fn ($q) => $q->where('type', TransactionType::Deposit->value)], 'amount')
            ->withSum(['transactions as withdrawals_total' => fn ($q) => $q->where('type', TransactionType::Withdrawal->value)], 'amount')
            ->get()
            ->keyBy('name');

        $current = (bool) $this->option('current');
        $errors = [];
        $seen = [];
        $plan = [];

        foreach ($rows as $row) {
            $row['type'] = strtolower($row['type']);

            // Same rules as the Accounts page form (uniqueness within the
            // journal is handled by upserting on name; within the file, below).
            $validator = Validator::make($row, [
                'name' => ['required', 'string', 'max:100'],
                'type' => ['required', Rule::in(array_column(AccountType::cases(), 'value'))],
                'starting_balance' => [$current ? 'required' : 'nullable', 'numeric', 'min:0'],
                'connection' => ['nullable', 'string', 'max:100'],
            ]);

            foreach ($validator->errors()->all() as $message) {
                $errors[] = "Line {$row['line']}: {$message}";
            }

            if ($row['name'] !== '' && isset($seen[$row['name']])) {
                $errors[] = "Line {$row['line']}: {$row['name']} already appears on line {$seen[$row['name']]}.";
            }
            $seen[$row['name']] ??= $row['line'];

            if ($validator->fails()) {
                continue;
            }

            /** @var Account|null $account */
            $account = $existing->get($row['name']);
            $journaled = (float) ($account->trades_sum_net_pnl ?? 0);
            $deposits = (float) ($account->deposits_total ?? 0);
            $withdrawals = (float) ($account->withdrawals_total ?? 0);

            $starting = $row['starting_balance'] === '' ? null : round((float) $row['starting_balance'], 2);
            if ($current) {
                $starting = round($starting - $journaled - $deposits + $withdrawals, 2);
                if ($starting < 0) {
                    $errors[] = "Line {$row['line']}: {$row['name']} would get a negative starting balance ("
                        .$this->money($starting).') after backing out journaled P&L and transactions.';

                    continue;
                }
            }

            $plan[] = [
                'name' => $row['name'],
                'type' => AccountType::from($row['type']),
                'starting_balance' => $starting,
                'connection' => $row['connection'] !== '' ? $row['connection'] : null,
                'account' => $account,
                'trades' => (int) ($account->trades_count ?? 0),
                // Mirrors the Accounts page: no balance shown without a
                // starting balance or any transactions.
                'balance_shown' => $starting === null && $deposits == 0 && $withdrawals == 0
                    ? null
                    : round(($starting ?? 0) + $journaled + $deposits - $withdrawals, 2),
            ];
        }

        if ($errors) {
            $this->reportErrors($errors);

            return null;
        }

        return $plan;
    }

    private function reportErrors(array $errors): void
    {
        foreach ($errors as $error) {
            $this->error($error);
        }
        $this->error('Nothing was imported.');
    }

    private function money(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 2);
    }
}
