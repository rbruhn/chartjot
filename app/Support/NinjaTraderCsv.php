<?php

namespace App\Support;

use App\Enums\ExitReason;
use App\Models\Account;
use App\Models\Journal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Parsing rules shared by the NinjaTrader 8 CSV importers (the Executions
 * export and the Trades export), so both read accounts, times and exit
 * names exactly the same way.
 */
class NinjaTraderCsv
{
    /** Timestamp format NT8 grids export, e.g. "1/5/2026 9:30:00 AM" (account-local time). */
    public const TIME_FORMAT = 'n/j/Y g:i:s A';

    /**
     * "APEX-24570-135!Apex!Apex" → ['APEX-24570-135', 'Apex']; a plain name
     * keeps the Connection column (or 'Unknown').
     *
     * @return array{0: string, 1: string}
     */
    public static function parseAccountName(string $raw, string $connectionColumn = ''): array
    {
        if (str_contains($raw, '!')) {
            $parts = explode('!', $raw);

            return [trim($parts[0]), trim($parts[1] ?? $connectionColumn)];
        }

        return [$raw, $connectionColumn ?: 'Unknown'];
    }

    /** "ES 12-26" → "ES" */
    public static function extractSymbol(string $instrument): string
    {
        return explode(' ', $instrument)[0];
    }

    /** Parse an NT8 timestamp in the given (account's) timezone. */
    public static function parseTime(string $raw, string $timezone): Carbon
    {
        return Carbon::createFromFormat(self::TIME_FORMAT, $raw, $timezone);
    }

    /** Strict check: rejects unparseable values and overflow like 13/45/2026. */
    public static function isValidTime(string $raw): bool
    {
        $parsed = \DateTime::createFromFormat(self::TIME_FORMAT, $raw);
        $issues = \DateTime::getLastErrors();

        return $parsed !== false
            && ($issues === false || ($issues['warning_count'] === 0 && $issues['error_count'] === 0));
    }

    /**
     * Each account's effective timezone (its own override, else the
     * journal's), keyed by account name. Times in a CSV are local to the
     * account that traded, so they must be read per account.
     *
     * @return Collection<string, string>
     */
    public static function accountTimezones(Journal $journal): Collection
    {
        return $journal->accounts()
            ->get(['name', 'timezone'])
            ->mapWithKeys(fn (Account $a) => [$a->name => $a->timezone ?: $journal->timezone]);
    }

    /** Exit reason from an order name: Stop…, …Target…, Close/Exit/Flatten, else Other. */
    public static function exitReasonFromName(string $name): ExitReason
    {
        $lower = strtolower($name);

        if (str_contains($lower, 'stop')) {
            return ExitReason::Stop;
        }
        if (str_contains($lower, 'target')) {
            return ExitReason::ProfitTarget;
        }
        if (in_array($lower, ['close', 'exit', 'flatten'])) {
            return ExitReason::Exit;
        }

        return ExitReason::Other;
    }
}
