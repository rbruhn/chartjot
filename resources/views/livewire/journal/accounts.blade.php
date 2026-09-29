<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Journal;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component
{
    public Journal $journal;

    public bool $creating   = false;
    public ?int $editingId  = null;
    public ?int $confirmDeleteId = null;

    public string $name            = '';
    public string $accountType     = 'funded';
    public string $startingBalance = '';
    public string $connection      = '';

    #[Computed]
    public function accounts()
    {
        return $this->journal->accounts()
            ->withCount('trades')
            ->withSum('trades', 'net_pnl')
            ->orderBy('name')
            ->get();
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

    public function confirmDelete(int $id): void
    {
        $this->confirmDeleteId = $id;
    }

    public function cancelDelete(): void
    {
        $this->confirmDeleteId = null;
    }

    public function delete(int $id): void
    {
        $account = $this->journal->accounts()->withCount('trades')->findOrFail($id);

        if ($account->trades_count > 0) {
            $this->addError('delete', "Cannot delete \"{$account->name}\" — it has {$account->trades_count} trade(s). Remove the trades first.");
            $this->confirmDeleteId = null;
            return;
        }

        $account->delete();
        $this->confirmDeleteId = null;
        unset($this->accounts);
    }

    public function cancel(): void
    {
        $this->creating        = false;
        $this->editingId       = null;
        $this->confirmDeleteId = null;
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
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

        {{-- Header --}}
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100">Accounts</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    Manage your trading accounts. Create accounts here before importing trades from NinjaTrader.
                </p>
            </div>
            @if(!$creating && !$editingId)
            <button wire:click="startCreate"
                class="inline-flex items-center gap-2 rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
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
                        $balance   = $account->starting_balance !== null ? (float) $account->starting_balance + $netPnl : null;
                        $pnlClass  = $netPnl >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400';
                    @endphp
                    <tr>
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
                        <td style="padding:0.875rem 1rem;text-align:right">
                            @if($confirmDeleteId === $account->id)
                            <span class="text-gray-700 dark:text-gray-300" style="font-size:0.8125rem;margin-right:0.5rem">Delete?</span>
                            <button wire:click="delete({{ $account->id }})"
                                class="rounded bg-red-100 text-red-700 hover:bg-red-200 dark:bg-red-900/30 dark:text-red-300 dark:hover:bg-red-900/50"
                                style="padding:0.25rem 0.625rem;font-size:0.75rem;cursor:pointer;margin-right:0.25rem">
                                Yes
                            </button>
                            <button wire:click="cancelDelete"
                                class="rounded bg-gray-200 text-gray-700 hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                                style="padding:0.25rem 0.625rem;font-size:0.75rem;cursor:pointer">
                                No
                            </button>
                            @else
                            <button wire:click="startEdit({{ $account->id }})"
                                class="rounded border border-gray-300 bg-white text-gray-600 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:text-gray-100"
                                style="padding:0.25rem 0.625rem;font-size:0.75rem;cursor:pointer;margin-right:0.25rem">
                                Edit
                            </button>
                            <button wire:click="confirmDelete({{ $account->id }})"
                                class="rounded border border-gray-300 bg-white text-gray-600 hover:text-red-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:text-red-400"
                                style="padding:0.25rem 0.625rem;font-size:0.75rem;cursor:pointer">
                                Delete
                            </button>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif

    </div>
</div>
