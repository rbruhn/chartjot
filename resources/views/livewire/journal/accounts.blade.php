<?php

use App\Enums\AccountType;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Journal;
use App\Models\TradeScreenshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component
{
    public Journal $journal;

    public bool $creating   = false;
    public ?int $editingId  = null;

    public string $name            = '';
    public string $accountType     = 'funded';
    public string $startingBalance = '';
    public string $connection      = '';

    // Deposits/withdrawals ledger (funded accounts only)
    public ?int $expandedAccountId = null;
    public string $txType          = 'deposit';
    public string $txAmount        = '';
    public string $txDate          = '';

    public ?int $editingTransactionId = null;
    public string $editTxType         = '';
    public string $editTxAmount       = '';
    public string $editTxDate         = '';

    // #99: merging a hand-made account into the one NT8 sends trades to
    public ?int $mergingId       = null;
    public string $mergeTargetId = '';

    #[Computed]
    public function accounts()
    {
        return $this->withBalanceSums($this->journal->accounts()->withCount('trades'))
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function expandedTransactions()
    {
        if ($this->expandedAccountId === null) {
            return collect();
        }

        return $this->journal->accounts()->findOrFail($this->expandedAccountId)
            ->transactions()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();
    }

    #[Computed]
    public function transactionTypes(): array
    {
        return TransactionType::cases();
    }

    /**
     * Starting balance + net P&L + deposits − withdrawals, from the aggregates
     * loaded by withBalanceSums(). Null when the account has neither a starting
     * balance nor any ledger entries — there's nothing to anchor it to.
     */
    public function balanceFor(Account $account): ?float
    {
        $deposits    = (float) ($account->deposits_total ?? 0);
        $withdrawals = (float) ($account->withdrawals_total ?? 0);

        if ($account->starting_balance === null && $deposits == 0 && $withdrawals == 0) {
            return null;
        }

        return (float) ($account->starting_balance ?? 0)
            + (float) ($account->trades_sum_net_pnl ?? 0)
            + $deposits
            - $withdrawals;
    }

    private function withBalanceSums($query)
    {
        return $query
            ->withSum('trades', 'net_pnl')
            ->withSum(['transactions as deposits_total' => fn ($q) => $q->where('type', TransactionType::Deposit->value)], 'amount')
            ->withSum(['transactions as withdrawals_total' => fn ($q) => $q->where('type', TransactionType::Withdrawal->value)], 'amount');
    }

    #[Computed]
    public function accountTypes(): array
    {
        return AccountType::cases();
    }

    public function startCreate(): void
    {
        $this->resetForm();
        $this->creating  = true;
        $this->editingId = null;
    }

    public function startEdit(int $id): void
    {
        $account = $this->journal->accounts()->findOrFail($id);
        $this->name            = $account->name;
        $this->accountType     = $account->account_type?->value ?? 'funded';
        $this->startingBalance = $account->starting_balance !== null ? number_format((float) $account->starting_balance, 2, '.', '') : '';
        $this->connection      = $account->connection ?? '';
        $this->editingId       = $id;
        $this->creating        = false;
        unset($this->accounts);
    }

    public function save(): void
    {
        $uniqueRule = Rule::unique('accounts', 'name')->where('journal_id', $this->journal->id);
        if ($this->editingId) {
            $uniqueRule = $uniqueRule->ignore($this->editingId);
        }

        $this->validate([
            'name'            => ['required', 'string', 'max:100', $uniqueRule],
            'accountType'     => ['required', Rule::in(array_column(AccountType::cases(), 'value'))],
            'startingBalance' => ['nullable', 'numeric', 'min:0'],
            'connection'      => ['nullable', 'string', 'max:100'],
        ]);

        $data = [
            'name'             => $this->name,
            'account_type'     => AccountType::from($this->accountType),
            'starting_balance' => $this->startingBalance !== '' ? (float) $this->startingBalance : null,
            // No per-account timezone override via the UI — always falls back to
            // the journal's timezone (Account::effectiveTimezone()). A wrong
            // per-account override silently corrupts imported trade times with
            // no way to detect it, so this field isn't worth the risk for the
            // rare case where it'd actually differ from the journal default.
            'timezone'         => null,
            'connection'       => $this->connection ?: null,
        ];

        if ($this->creating) {
            $this->journal->accounts()->create($data);
        } else {
            $this->journal->accounts()->findOrFail($this->editingId)->update($data);
        }

        $this->cancel();
        unset($this->accounts);
    }

    public function delete(int $id): void
    {
        $account = $this->journal->accounts()->withCount('trades')->findOrFail($id);

        if ($account->trades_count > 0) {
            $this->addError('delete', "Cannot delete \"{$account->name}\" — it has {$account->trades_count} trade(s). Clear the trades first.");
            return;
        }

        $account->delete();
        unset($this->accounts);
    }

    public function clearTrades(int $id): void
    {
        $account = $this->journal->accounts()->findOrFail($id);

        $screenshots = TradeScreenshot::whereIn('trade_id', $account->trades()->select('id'))->get();

        // Executions, legs, screenshots, and notes all cascadeOnDelete at the
        // DB level — only the screenshot files need explicit cleanup.
        DB::transaction(fn () => $account->trades()->delete());

        foreach ($screenshots as $shot) {
            Storage::disk($shot->disk)->delete($shot->path);
        }

        unset($this->accounts);
    }

    public function startMerge(int $id): void
    {
        $this->journal->accounts()->findOrFail($id);

        $this->mergingId     = $id;
        $this->mergeTargetId = '';
        $this->resetErrorBag('mergeTargetId');
    }

    public function cancelMerge(): void
    {
        $this->mergingId     = null;
        $this->mergeTargetId = '';
        $this->resetErrorBag('mergeTargetId');
    }

    /**
     * #99: moves the merging account's trades and transactions to the target and deletes it. The target keeps its
     * name, since that's the NT8 account name trades arrive under. The merging account is the one the trader set up
     * by hand, so its type and starting balance win; the target's connection and timezone are only filled in where
     * it has none.
     */
    public function merge(): void
    {
        $source = $this->journal->accounts()->findOrFail((int) $this->mergingId);

        $this->validate([
            'mergeTargetId' => [
                'required',
                'integer',
                Rule::notIn([$source->id]),
                Rule::exists('accounts', 'id')->where('journal_id', $this->journal->id),
            ],
        ], [
            'mergeTargetId.required' => 'Choose the account to merge into.',
            'mergeTargetId.not_in'   => 'Choose a different account to merge into.',
            'mergeTargetId.exists'   => 'Choose an account in this journal.',
        ]);

        $target = $this->journal->accounts()->findOrFail((int) $this->mergeTargetId);

        DB::transaction(function () use ($source, $target) {
            $source->trades()->update(['account_id' => $target->id]);
            $source->transactions()->update(['account_id' => $target->id]);

            $target->update([
                'account_type'     => $source->account_type,
                'starting_balance' => $source->starting_balance ?? $target->starting_balance,
                'connection'       => $target->connection ?? $source->connection,
                'timezone'         => $target->timezone ?? $source->timezone,
            ]);

            $source->delete();
        });

        if ($this->expandedAccountId === $source->id) {
            $this->expandedAccountId = null;
        }
        $this->cancelMerge();
        unset($this->accounts, $this->expandedTransactions);
    }

    public function toggleTransactions(int $id): void
    {
        if ($this->expandedAccountId === $id) {
            $this->expandedAccountId = null;
            return;
        }

        $this->fundedAccount($id);

        $this->expandedAccountId = $id;
        $this->resetTransactionForm();
        $this->cancelEditTransaction();
        unset($this->expandedTransactions);
    }

    public function addTransaction(): void
    {
        $account = $this->fundedAccount((int) $this->expandedAccountId);

        $this->validate([
            'txType'   => ['required', Rule::in(array_column(TransactionType::cases(), 'value'))],
            'txAmount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'txDate'   => ['required', 'date'],
        ]);

        $type   = TransactionType::from($this->txType);
        $amount = round((float) $this->txAmount, 2);

        if (!$this->withdrawalFits($account, $type, $amount, null, 'txAmount')) {
            return;
        }

        $account->transactions()->create([
            'type'        => $type,
            'amount'      => $amount,
            'occurred_at' => $this->txDate,
        ]);

        // Leave the ledger row open so several entries can be added in a row.
        $this->resetTransactionForm();
        unset($this->accounts, $this->expandedTransactions);
    }

    public function startEditTransaction(int $id): void
    {
        $tx = $this->expandedTransaction($id);

        $this->editingTransactionId = $tx->id;
        $this->editTxType           = $tx->type->value;
        $this->editTxAmount         = number_format((float) $tx->amount, 2, '.', '');
        $this->editTxDate           = $tx->occurred_at->toDateString();
        $this->resetErrorBag(['editTxType', 'editTxAmount', 'editTxDate']);
    }

    public function cancelEditTransaction(): void
    {
        $this->editingTransactionId = null;
        $this->editTxType           = '';
        $this->editTxAmount         = '';
        $this->editTxDate           = '';
        $this->resetErrorBag(['editTxType', 'editTxAmount', 'editTxDate']);
    }

    public function saveTransaction(): void
    {
        $tx = $this->expandedTransaction((int) $this->editingTransactionId);

        $this->validate([
            'editTxType'   => ['required', Rule::in(array_column(TransactionType::cases(), 'value'))],
            'editTxAmount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'editTxDate'   => ['required', 'date'],
        ]);

        $type   = TransactionType::from($this->editTxType);
        $amount = round((float) $this->editTxAmount, 2);

        if (!$this->withdrawalFits($tx->account, $type, $amount, $tx, 'editTxAmount')) {
            return;
        }

        $tx->update([
            'type'        => $type,
            'amount'      => $amount,
            'occurred_at' => $this->editTxDate,
        ]);

        $this->cancelEditTransaction();
        unset($this->accounts, $this->expandedTransactions);
    }

    public function deleteTransaction(int $id): void
    {
        $tx = $this->expandedTransaction($id);

        if ($this->editingTransactionId === $tx->id) {
            $this->cancelEditTransaction();
        }

        $tx->delete();
        unset($this->accounts, $this->expandedTransactions);
    }

    /**
     * A withdrawal may not exceed the account's current balance. When editing,
     * the entry being replaced is backed out of the balance first, so e.g.
     * bumping a $500 withdrawal to $600 only needs $100 more headroom.
     * The balance is re-read at save time rather than trusted from the
     * rendered table, which may be stale (e.g. an import landed trades since
     * the page loaded).
     */
    private function withdrawalFits(Account $account, TransactionType $type, float $amount, ?AccountTransaction $replacing, string $errorKey): bool
    {
        if ($type !== TransactionType::Withdrawal) {
            return true;
        }

        $current = $this->withBalanceSums($this->journal->accounts())->findOrFail($account->id);
        $balance = $this->balanceFor($current) ?? 0;

        if ($replacing !== null) {
            $balance += $replacing->type === TransactionType::Withdrawal
                ? (float) $replacing->amount
                : -(float) $replacing->amount;
        }

        $balance = round($balance, 2);

        if ($amount > $balance) {
            $this->addError($errorKey, 'Withdrawal cannot exceed the current balance of $'.number_format($balance, 2).'.');
            return false;
        }

        return true;
    }

    /** A transaction on the currently expanded (funded, in-journal) account. */
    private function expandedTransaction(int $id): AccountTransaction
    {
        return $this->fundedAccount((int) $this->expandedAccountId)
            ->transactions()
            ->findOrFail($id);
    }

    private function fundedAccount(int $id): Account
    {
        return $this->journal->accounts()
            ->where('account_type', AccountType::Funded->value)
            ->findOrFail($id);
    }

    private function resetTransactionForm(): void
    {
        $this->txType   = TransactionType::Deposit->value;
        $this->txAmount = '';
        $this->txDate   = now($this->journal->timezone ?? 'UTC')->toDateString();
        $this->resetErrorBag(['txType', 'txAmount', 'txDate']);
    }

    public function cancel(): void
    {
        $this->creating        = false;
        $this->editingId       = null;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->name            = '';
        $this->accountType     = 'funded';
        $this->startingBalance = '';
        $this->connection      = '';
        $this->resetErrorBag();
    }
}; ?>

<div class="py-8">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

        {{-- Header --}}
        <div class="flex flex-col items-start gap-4 sm:flex-row sm:justify-between sm:gap-6">
            <div style="max-width:48rem">
                <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100">Accounts</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    If you use the Chart Jot AddOn, your accounts appear here on their first trade; then set their type and starting balance.
                    Only add accounts by hand for CSV imports, using the exact NinjaTrader account name.
                </p>
            </div>
            @if(!$creating && !$editingId)
            <button wire:click="startCreate"
                class="inline-flex shrink-0 items-center gap-2 whitespace-nowrap rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
                style="cursor:pointer">
                <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                Add Account
            </button>
            @endif
        </div>

        {{-- Delete error --}}
        @error('delete')
        <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/30 dark:text-red-300">
            {{ $message }}
        </div>
        @enderror

        {{-- Create / Edit form --}}
        @if($creating || $editingId)
        <div class="rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900" style="padding:1.5rem">
            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100 mb-4">
                {{ $creating ? 'New Account' : 'Edit Account' }}
            </h3>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                {{-- Name --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Account Name <span class="text-red-500 dark:text-red-400">*</span></label>
                    <input wire:model="name" type="text" placeholder="e.g. Test-123456"
                        class="w-full rounded-md border border-gray-300 bg-white text-gray-900 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                        style="padding:0.5rem 0.75rem;font-size:0.875rem;box-sizing:border-box">
                    @error('name')<p class="mt-1 text-xs text-red-500 dark:text-red-400">{{ $message }}</p>@enderror
                </div>

                {{-- Type --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Account Type <span class="text-red-500 dark:text-red-400">*</span></label>
                    <select wire:model="accountType"
                        class="w-full rounded-md border border-gray-300 bg-white text-gray-900 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                        style="padding:0.5rem 0.75rem;font-size:0.875rem;box-sizing:border-box">
                        @foreach($this->accountTypes as $type)
                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                        @endforeach
                    </select>
                    @error('accountType')<p class="mt-1 text-xs text-red-500 dark:text-red-400">{{ $message }}</p>@enderror
                </div>

                {{-- Starting Balance --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Starting Balance</label>
                    <div style="position:relative">
                        <span class="text-gray-500 dark:text-gray-400" style="position:absolute;left:0.75rem;top:50%;transform:translateY(-50%);font-size:0.875rem">$</span>
                        <input wire:model="startingBalance" type="number" min="0" step="0.01" placeholder="50000.00"
                            class="w-full rounded-md border border-gray-300 bg-white text-gray-900 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                            style="padding:0.5rem 0.75rem 0.5rem 1.5rem;font-size:0.875rem;box-sizing:border-box">
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-500">Balance before your first trade in this journal.</p>
                    @error('startingBalance')<p class="mt-1 text-xs text-red-500 dark:text-red-400">{{ $message }}</p>@enderror
                </div>

                {{-- Connection --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Connection</label>
                    <input wire:model="connection" type="text" placeholder="e.g. Rithmic, Tradovate"
                        class="w-full rounded-md border border-gray-300 bg-white text-gray-900 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                        style="padding:0.5rem 0.75rem;font-size:0.875rem;box-sizing:border-box">
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-500">Optional — auto-filled from imports if left blank.</p>
                    @error('connection')<p class="mt-1 text-xs text-red-500 dark:text-red-400">{{ $message }}</p>@enderror
                </div>
            </div>

            <div style="display:flex;gap:0.75rem;margin-top:1.25rem">
                <button wire:click="save"
                    class="rounded-md bg-blue-600 text-white hover:bg-blue-700"
                    style="padding:0.5rem 1.25rem;font-size:0.875rem;font-weight:500;cursor:pointer">
                    Save
                </button>
                <button wire:click="cancel"
                    class="rounded-md bg-gray-200 text-gray-700 hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                    style="padding:0.5rem 1.25rem;font-size:0.875rem;cursor:pointer">
                    Cancel
                </button>
            </div>
        </div>
        @endif

        {{-- Accounts table --}}
        @if($this->accounts->isEmpty() && !$creating)
        <div class="rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900" style="padding:3rem;text-align:center">
            <p class="text-gray-500 dark:text-gray-400 text-sm">No accounts yet. Add your first account to get started.</p>
        </div>
        @else
        <div class="rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900" style="overflow:hidden">
            <table style="width:100%;border-collapse:collapse;font-size:0.875rem">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-700">
                        <th class="text-gray-500 dark:text-gray-400" style="text-align:left;padding:0.75rem 1rem;font-weight:500;font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em">Name</th>
                        <th class="text-gray-500 dark:text-gray-400" style="text-align:left;padding:0.75rem 1rem;font-weight:500;font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em">Type</th>
                        <th class="text-gray-500 dark:text-gray-400" style="text-align:right;padding:0.75rem 1rem;font-weight:500;font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em">Starting Balance</th>
                        <th class="text-gray-500 dark:text-gray-400" style="text-align:right;padding:0.75rem 1rem;font-weight:500;font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em">Net P&amp;L</th>
                        <th class="text-gray-500 dark:text-gray-400" style="text-align:right;padding:0.75rem 1rem;font-weight:500;font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em">Current Balance</th>
                        <th class="text-gray-500 dark:text-gray-400" style="text-align:right;padding:0.75rem 1rem;font-weight:500;font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em">Trades</th>
                        <th style="padding:0.75rem 1rem"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach($this->accounts as $account)
                    @php
                        $netPnl    = (float) ($account->trades_sum_net_pnl ?? 0);
                        $balance   = $this->balanceFor($account);
                        $pnlClass  = $netPnl >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400';
                    @endphp
                    <tr wire:key="account-{{ $account->id }}">
                        <td class="text-gray-900 dark:text-gray-50" style="padding:0.875rem 1rem;font-weight:500">
                            {{ $account->name }}
                            @if($account->connection)
                            <span class="text-gray-500 dark:text-gray-500" style="margin-left:0.5rem;font-size:0.75rem">{{ $account->connection }}</span>
                            @endif
                        </td>
                        <td style="padding:0.875rem 1rem">
                            @php
                                $typeClasses = [
                                    'sim'    => 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
                                    'eval'   => 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400',
                                    'funded' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
                                ];
                                $tc = $typeClasses[$account->account_type?->value ?? 'funded'] ?? $typeClasses['funded'];
                            @endphp
                            <span class="{{ $tc }}" style="display:inline-block;padding:0.125rem 0.5rem;border-radius:9999px;font-size:0.75rem;font-weight:500">
                                {{ $account->account_type?->label() ?? 'Funded' }}
                            </span>
                        </td>
                        <td class="text-gray-700 dark:text-gray-300" style="padding:0.875rem 1rem;text-align:right">
                            {{ $account->starting_balance !== null ? '$'.number_format((float)$account->starting_balance, 2) : '—' }}
                        </td>
                        <td class="{{ $pnlClass }}" style="padding:0.875rem 1rem;text-align:right;font-weight:500">
                            {{ $netPnl >= 0 ? '+' : '' }}${{ number_format(abs($netPnl), 2) }}
                        </td>
                        <td class="text-gray-900 dark:text-gray-50" style="padding:0.875rem 1rem;text-align:right;font-weight:500">
                            {{ $balance !== null ? '$'.number_format($balance, 2) : '—' }}
                        </td>
                        <td class="text-gray-500 dark:text-gray-400" style="padding:0.875rem 1rem;text-align:right">
                            {{ number_format($account->trades_count) }}
                        </td>
                        <td style="padding:0.875rem 1rem;text-align:right;white-space:nowrap">
                            @if($account->account_type === AccountType::Funded)
                            <button wire:click="toggleTransactions({{ $account->id }})"
                                title="Transactions" aria-label="Transactions"
                                class="rounded border {{ $expandedAccountId === $account->id ? 'border-blue-400 bg-blue-50 text-blue-700 dark:border-blue-500 dark:bg-blue-900/30 dark:text-blue-300' : 'border-gray-300 bg-white text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400' }} hover:text-gray-900 dark:hover:text-gray-100"
                                style="display:inline-flex;align-items:center;vertical-align:middle;padding:0.3125rem;cursor:pointer;margin-right:0.25rem">
                                <x-heroicon-o-banknotes class="h-4 w-4" />
                            </button>
                            @endif
                            <button wire:click="startEdit({{ $account->id }})"
                                title="Edit" aria-label="Edit"
                                class="rounded border border-gray-300 bg-white text-gray-600 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:text-gray-100"
                                style="display:inline-flex;align-items:center;vertical-align:middle;padding:0.3125rem;cursor:pointer;margin-right:0.25rem">
                                <x-heroicon-o-pencil-square class="h-4 w-4" />
                            </button>
                            @if($this->accounts->count() > 1)
                            <button wire:click="startMerge({{ $account->id }})"
                                title="Merge into another account" aria-label="Merge into another account"
                                class="rounded border {{ $mergingId === $account->id ? 'border-blue-400 bg-blue-50 text-blue-700 dark:border-blue-500 dark:bg-blue-900/30 dark:text-blue-300' : 'border-gray-300 bg-white text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400' }} hover:text-gray-900 dark:hover:text-gray-100"
                                style="display:inline-flex;align-items:center;vertical-align:middle;padding:0.3125rem;cursor:pointer;margin-right:0.25rem">
                                <x-heroicon-o-arrows-pointing-in class="h-4 w-4" />
                            </button>
                            @endif
                            @if($account->trades_count > 0)
                            <button wire:click="clearTrades({{ $account->id }})"
                                wire:confirm="Clear all {{ number_format($account->trades_count) }} trade(s) from &quot;{{ $account->name }}&quot;? All trades and images will be lost. This cannot be undone."
                                title="Clear" aria-label="Clear"
                                class="rounded border border-gray-300 bg-white text-gray-600 hover:text-red-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:text-red-400"
                                style="display:inline-flex;align-items:center;vertical-align:middle;padding:0.3125rem;cursor:pointer;margin-right:0.25rem">
                                <x-heroicon-o-arrow-path class="h-4 w-4" />
                            </button>
                            @endif
                            <button wire:click="delete({{ $account->id }})"
                                wire:confirm="Delete account &quot;{{ $account->name }}&quot;? This cannot be undone."
                                title="Delete" aria-label="Delete"
                                class="rounded border border-gray-300 bg-white text-gray-600 hover:text-red-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:text-red-400"
                                style="display:inline-flex;align-items:center;vertical-align:middle;padding:0.3125rem;cursor:pointer">
                                <x-heroicon-o-trash class="h-4 w-4" />
                            </button>
                        </td>
                    </tr>
                    @if($mergingId === $account->id)
                    <tr wire:key="account-{{ $account->id }}-merge">
                        <td colspan="7" class="bg-gray-50 dark:bg-gray-800/50" style="padding:1rem 1.25rem">
                            <h4 class="text-gray-700 dark:text-gray-300" style="font-size:0.75rem;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.5rem">
                                Merge &ldquo;{{ $account->name }}&rdquo; into another account
                            </h4>
                            <p class="text-gray-600 dark:text-gray-400" style="font-size:0.8125rem;margin-bottom:0.75rem;max-width:48rem">
                                Use this when NinjaTrader sent trades under a different name than the account you created.
                                Choose the account named exactly as in NinjaTrader. Its name is kept; it takes this account's
                                type{{ $account->starting_balance !== null ? ' and starting balance' : '' }},
                                and this account's {{ number_format($account->trades_count) }} trade(s) and its deposits and withdrawals move to it.
                                &ldquo;{{ $account->name }}&rdquo; is then deleted.
                            </p>
                            <div style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:flex-start">
                                <div>
                                    <select wire:model="mergeTargetId" aria-label="Merge into"
                                        class="rounded-md border border-gray-300 bg-white text-gray-900 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                                        style="padding:0.375rem 2rem 0.375rem 0.75rem;font-size:0.8125rem">
                                        <option value="">Merge into…</option>
                                        @foreach($this->accounts->where('id', '!=', $account->id) as $target)
                                        <option value="{{ $target->id }}">{{ $target->name }}{{ $target->connection ? ' ('.$target->connection.')' : '' }}</option>
                                        @endforeach
                                    </select>
                                    @error('mergeTargetId')<p class="mt-1 text-xs text-red-500 dark:text-red-400">{{ $message }}</p>@enderror
                                </div>
                                <button wire:click="merge"
                                    wire:confirm="Merge &quot;{{ $account->name }}&quot; into the selected account? &quot;{{ $account->name }}&quot; will be deleted. This cannot be undone."
                                    class="rounded-md bg-blue-600 text-white hover:bg-blue-700"
                                    style="padding:0.375rem 1rem;font-size:0.8125rem;font-weight:500;cursor:pointer">
                                    Merge
                                </button>
                                <button wire:click="cancelMerge"
                                    class="rounded-md bg-gray-200 text-gray-700 hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                                    style="padding:0.375rem 1rem;font-size:0.8125rem;cursor:pointer">
                                    Cancel
                                </button>
                            </div>
                        </td>
                    </tr>
                    @endif
                    @if($expandedAccountId === $account->id && $account->account_type === AccountType::Funded)
                    <tr wire:key="account-{{ $account->id }}-transactions">
                        <td colspan="7" class="bg-gray-50 dark:bg-gray-800/50" style="padding:1rem 1.25rem">
                            <h4 class="text-gray-700 dark:text-gray-300" style="font-size:0.75rem;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.75rem">
                                Deposits &amp; Withdrawals
                            </h4>

                            @if($this->expandedTransactions->isEmpty())
                            <p class="text-gray-500 dark:text-gray-400" style="font-size:0.8125rem;margin-bottom:1rem">No deposits or withdrawals yet.</p>
                            @else
                            <table style="width:100%;max-width:40rem;border-collapse:collapse;font-size:0.8125rem;margin-bottom:1rem">
                                <thead>
                                    <tr class="border-b border-gray-200 dark:border-gray-700">
                                        <th class="text-gray-500 dark:text-gray-400" style="text-align:left;padding:0.375rem 0.5rem;font-weight:500;font-size:0.6875rem;text-transform:uppercase;letter-spacing:0.05em">Date</th>
                                        <th class="text-gray-500 dark:text-gray-400" style="text-align:left;padding:0.375rem 0.5rem;font-weight:500;font-size:0.6875rem;text-transform:uppercase;letter-spacing:0.05em">Type</th>
                                        <th class="text-gray-500 dark:text-gray-400" style="text-align:right;padding:0.375rem 0.5rem;font-weight:500;font-size:0.6875rem;text-transform:uppercase;letter-spacing:0.05em">Amount</th>
                                        <th style="padding:0.375rem 0.5rem"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                    @foreach($this->expandedTransactions as $tx)
                                    @php
                                        $isDeposit = $tx->type === TransactionType::Deposit;
                                        $txc = $isDeposit
                                            ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400'
                                            : 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400';
                                    @endphp
                                    @if($editingTransactionId === $tx->id)
                                    <tr wire:key="tx-{{ $tx->id }}-edit">
                                        <td style="padding:0.25rem 0.5rem">
                                            <input wire:model="editTxDate" type="date" aria-label="Date"
                                                class="rounded-md border border-gray-300 bg-white text-gray-900 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                                                style="padding:0.25rem 0.5rem;font-size:0.8125rem;box-sizing:border-box">
                                        </td>
                                        <td style="padding:0.25rem 0.5rem">
                                            <select wire:model="editTxType" aria-label="Type"
                                                class="rounded-md border border-gray-300 bg-white text-gray-900 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                                                style="padding:0.25rem 2rem 0.25rem 0.5rem;font-size:0.8125rem;box-sizing:border-box">
                                                @foreach($this->transactionTypes as $type)
                                                <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td style="padding:0.25rem 0.5rem;text-align:right">
                                            <div style="position:relative;display:inline-block">
                                                <span class="text-gray-500 dark:text-gray-400" style="position:absolute;left:0.5rem;top:50%;transform:translateY(-50%);font-size:0.8125rem">$</span>
                                                <input wire:model="editTxAmount" type="number" min="0.01" step="0.01" aria-label="Amount"
                                                    class="rounded-md border border-gray-300 bg-white text-gray-900 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                                                    style="width:8rem;padding:0.25rem 0.5rem 0.25rem 1.25rem;font-size:0.8125rem;box-sizing:border-box;text-align:right">
                                            </div>
                                        </td>
                                        <td style="padding:0.25rem 0.5rem;text-align:right;white-space:nowrap">
                                            <button wire:click="saveTransaction"
                                                class="rounded bg-blue-600 text-white hover:bg-blue-700 dark:bg-blue-600 dark:hover:bg-blue-500"
                                                style="padding:0.25rem 0.625rem;font-size:0.75rem;font-weight:500;cursor:pointer;margin-right:0.25rem">
                                                Save
                                            </button>
                                            <button wire:click="cancelEditTransaction"
                                                class="rounded bg-gray-200 text-gray-700 hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                                                style="padding:0.25rem 0.625rem;font-size:0.75rem;cursor:pointer">
                                                Cancel
                                            </button>
                                        </td>
                                    </tr>
                                    @else
                                    <tr wire:key="tx-{{ $tx->id }}">
                                        <td class="text-gray-700 dark:text-gray-300" style="padding:0.375rem 0.5rem">{{ $tx->occurred_at->format('M j, Y') }}</td>
                                        <td style="padding:0.375rem 0.5rem">
                                            <span class="{{ $txc }}" style="display:inline-block;padding:0.125rem 0.5rem;border-radius:9999px;font-size:0.75rem;font-weight:500">
                                                {{ $tx->type->label() }}
                                            </span>
                                        </td>
                                        <td class="text-gray-900 dark:text-gray-50" style="padding:0.375rem 0.5rem;text-align:right;font-weight:500">
                                            {{ $isDeposit ? '+' : '−' }}${{ number_format((float) $tx->amount, 2) }}
                                        </td>
                                        <td style="padding:0.375rem 0.5rem;text-align:right;white-space:nowrap">
                                            <button wire:click="startEditTransaction({{ $tx->id }})"
                                                title="Edit" aria-label="Edit"
                                                class="rounded border border-gray-300 bg-white text-gray-600 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:text-gray-100"
                                                style="display:inline-flex;align-items:center;vertical-align:middle;padding:0.25rem;cursor:pointer;margin-right:0.25rem">
                                                <x-heroicon-o-pencil-square class="h-3.5 w-3.5" />
                                            </button>
                                            <button wire:click="deleteTransaction({{ $tx->id }})"
                                                wire:confirm="Delete this {{ strtolower($tx->type->label()) }} of ${{ number_format((float) $tx->amount, 2) }} on {{ $tx->occurred_at->format('M j, Y') }}? This cannot be undone."
                                                title="Delete" aria-label="Delete"
                                                class="rounded border border-gray-300 bg-white text-gray-600 hover:text-red-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:text-red-400"
                                                style="display:inline-flex;align-items:center;vertical-align:middle;padding:0.25rem;cursor:pointer">
                                                <x-heroicon-o-trash class="h-3.5 w-3.5" />
                                            </button>
                                        </td>
                                    </tr>
                                    @endif
                                    @endforeach
                                </tbody>
                            </table>
                            @foreach(['editTxDate', 'editTxType', 'editTxAmount'] as $field)
                            @error($field)<p class="text-xs text-red-500 dark:text-red-400" style="margin-top:-0.75rem;margin-bottom:0.75rem">{{ $message }}</p>@enderror
                            @endforeach
                            @endif

                            {{-- Add entry --}}
                            <div style="display:flex;flex-wrap:wrap;align-items:flex-start;gap:0.5rem">
                                <div>
                                    <input wire:model="txDate" type="date" aria-label="Date"
                                        class="rounded-md border border-gray-300 bg-white text-gray-900 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                                        style="padding:0.375rem 0.625rem;font-size:0.8125rem;box-sizing:border-box">
                                </div>
                                <div>
                                    <select wire:model="txType" aria-label="Type"
                                        class="rounded-md border border-gray-300 bg-white text-gray-900 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                                        style="padding:0.375rem 2rem 0.375rem 0.625rem;font-size:0.8125rem;box-sizing:border-box">
                                        @foreach($this->transactionTypes as $type)
                                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <div style="position:relative">
                                        <span class="text-gray-500 dark:text-gray-400" style="position:absolute;left:0.625rem;top:50%;transform:translateY(-50%);font-size:0.8125rem">$</span>
                                        <input wire:model="txAmount" type="number" min="0.01" step="0.01" placeholder="1000.00" aria-label="Amount"
                                            class="rounded-md border border-gray-300 bg-white text-gray-900 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                                            style="width:9rem;padding:0.375rem 0.625rem 0.375rem 1.375rem;font-size:0.8125rem;box-sizing:border-box">
                                    </div>
                                </div>
                                <button wire:click="addTransaction"
                                    class="rounded-md bg-blue-600 text-white hover:bg-blue-700 dark:bg-blue-600 dark:hover:bg-blue-500"
                                    style="padding:0.375rem 1rem;font-size:0.8125rem;font-weight:500;cursor:pointer">
                                    Add
                                </button>
                            </div>
                            @foreach(['txDate', 'txType', 'txAmount'] as $field)
                            @error($field)<p class="mt-1 text-xs text-red-500 dark:text-red-400">{{ $message }}</p>@enderror
                            @endforeach
                        </td>
                    </tr>
                    @endif
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif

    </div>
</div>
