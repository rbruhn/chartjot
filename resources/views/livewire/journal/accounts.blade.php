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
    public string $timezone        = '';

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
        $this->accountType     = $account->account_type?->value ?? 'live';
        $this->startingBalance = $account->starting_balance !== null ? number_format((float) $account->starting_balance, 2, '.', '') : '';
        $this->timezone        = $account->timezone ?? '';
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
            'timezone'        => ['nullable', 'string', 'timezone:all'],
        ]);

        $data = [
            'name'             => $this->name,
            'account_type'     => AccountType::from($this->accountType),
            'starting_balance' => $this->startingBalance !== '' ? (float) $this->startingBalance : null,
            'timezone'         => $this->timezone ?: null,
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
        $this->accountType     = 'live';
        $this->startingBalance = '';
        $this->timezone        = '';
        $this->resetErrorBag();
    }
}; ?>

<div class="py-8">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

        {{-- Header --}}
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-100">Accounts</h2>
                <p class="mt-1 text-sm text-gray-400">
                    Manage your trading accounts. Create accounts here before importing trades from NinjaTrader.
                </p>
            </div>
            @if(!$creating && !$editingId)
            <button wire:click="startCreate"
                style="display:inline-flex;align-items:center;gap:0.5rem;padding:0.5rem 1rem;background:#2563eb;color:#fff;border:none;border-radius:0.375rem;font-size:0.875rem;font-weight:500;cursor:pointer"
                onmouseover="this.style.background='#1d4ed8'" onmouseout="this.style.background='#2563eb'">
                <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                Add Account
            </button>
            @endif
        </div>

        {{-- Delete error --}}
        @error('delete')
        <div style="background:#450a0a;border:1px solid #991b1b;border-radius:0.5rem;padding:0.75rem 1rem;color:#fca5a5;font-size:0.875rem">
            {{ $message }}
        </div>
        @enderror

        {{-- Create / Edit form --}}
        @if($creating || $editingId)
        <div style="background:#111827;border:1px solid #374151;border-radius:0.5rem;padding:1.5rem">
            <h3 class="text-base font-semibold text-gray-100 mb-4">
                {{ $creating ? 'New Account' : 'Edit Account' }}
            </h3>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                {{-- Name --}}
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-1">Account Name <span class="text-red-400">*</span></label>
                    <input wire:model="name" type="text" placeholder="e.g. Apex-123456"
                        style="width:100%;background:#1f2937;border:1px solid #374151;border-radius:0.375rem;padding:0.5rem 0.75rem;color:#f9fafb;font-size:0.875rem;box-sizing:border-box">
                    @error('name')<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
                </div>

                {{-- Type --}}
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-1">Account Type <span class="text-red-400">*</span></label>
                    <select wire:model="accountType"
                        style="width:100%;background:#1f2937;border:1px solid #374151;border-radius:0.375rem;padding:0.5rem 0.75rem;color:#f9fafb;font-size:0.875rem;box-sizing:border-box">
                        @foreach($this->accountTypes as $type)
                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                        @endforeach
                    </select>
                    @error('accountType')<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
                </div>

                {{-- Starting Balance --}}
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-1">Starting Balance</label>
                    <div style="position:relative">
                        <span style="position:absolute;left:0.75rem;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:0.875rem">$</span>
                        <input wire:model="startingBalance" type="number" min="0" step="0.01" placeholder="50000.00"
                            style="width:100%;background:#1f2937;border:1px solid #374151;border-radius:0.375rem;padding:0.5rem 0.75rem 0.5rem 1.5rem;color:#f9fafb;font-size:0.875rem;box-sizing:border-box">
                    </div>
                    <p class="mt-1 text-xs text-gray-500">Balance before your first trade in this journal.</p>
                    @error('startingBalance')<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
                </div>

                {{-- Timezone --}}
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-1">Timezone</label>
                    <input wire:model="timezone" type="text" placeholder="Leave blank to use journal timezone"
                        style="width:100%;background:#1f2937;border:1px solid #374151;border-radius:0.375rem;padding:0.5rem 0.75rem;color:#f9fafb;font-size:0.875rem;box-sizing:border-box">
                    <p class="mt-1 text-xs text-gray-500">e.g. America/Chicago — defaults to journal timezone if blank.</p>
                    @error('timezone')<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
                </div>
            </div>

            <div style="display:flex;gap:0.75rem;margin-top:1.25rem">
                <button wire:click="save"
                    style="padding:0.5rem 1.25rem;background:#2563eb;color:#fff;border:none;border-radius:0.375rem;font-size:0.875rem;font-weight:500;cursor:pointer"
                    onmouseover="this.style.background='#1d4ed8'" onmouseout="this.style.background='#2563eb'">
                    Save
                </button>
                <button wire:click="cancel"
                    style="padding:0.5rem 1.25rem;background:#374151;color:#d1d5db;border:none;border-radius:0.375rem;font-size:0.875rem;cursor:pointer"
                    onmouseover="this.style.background='#4b5563'" onmouseout="this.style.background='#374151'">
                    Cancel
                </button>
            </div>
        </div>
        @endif

        {{-- Accounts table --}}
        @if($this->accounts->isEmpty() && !$creating)
        <div style="background:#111827;border:1px solid #374151;border-radius:0.5rem;padding:3rem;text-align:center">
            <p class="text-gray-400 text-sm">No accounts yet. Add your first account to get started.</p>
        </div>
        @else
        <div style="background:#111827;border:1px solid #374151;border-radius:0.5rem;overflow:hidden">
            <table style="width:100%;border-collapse:collapse;font-size:0.875rem">
                <thead>
                    <tr style="border-bottom:1px solid #374151">
                        <th style="text-align:left;padding:0.75rem 1rem;color:#9ca3af;font-weight:500;font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em">Name</th>
                        <th style="text-align:left;padding:0.75rem 1rem;color:#9ca3af;font-weight:500;font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em">Type</th>
                        <th style="text-align:right;padding:0.75rem 1rem;color:#9ca3af;font-weight:500;font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em">Starting Balance</th>
                        <th style="text-align:right;padding:0.75rem 1rem;color:#9ca3af;font-weight:500;font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em">Net P&amp;L</th>
                        <th style="text-align:right;padding:0.75rem 1rem;color:#9ca3af;font-weight:500;font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em">Current Balance</th>
                        <th style="text-align:right;padding:0.75rem 1rem;color:#9ca3af;font-weight:500;font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em">Trades</th>
                        <th style="padding:0.75rem 1rem"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($this->accounts as $account)
                    @php
                        $netPnl    = (float) ($account->trades_sum_net_pnl ?? 0);
                        $balance   = $account->starting_balance !== null ? (float) $account->starting_balance + $netPnl : null;
                        $pnlColor  = $netPnl >= 0 ? '#4ade80' : '#f87171';
                    @endphp
                    <tr style="border-bottom:1px solid #1f2937{{ $loop->last ? ';border-bottom:none' : '' }}">
                        <td style="padding:0.875rem 1rem;color:#f9fafb;font-weight:500">
                            {{ $account->name }}
                            @if($account->connection)
                            <span style="margin-left:0.5rem;font-size:0.75rem;color:#6b7280">{{ $account->connection }}</span>
                            @endif
                        </td>
                        <td style="padding:0.875rem 1rem">
                            @php
                                $typeColors = [
                                    'sim'    => ['bg' => '#1e3a5f', 'text' => '#93c5fd'],
                                    'eval'   => ['bg' => '#3b1f5e', 'text' => '#d8b4fe'],
                                    'funded' => ['bg' => '#713f12', 'text' => '#fde68a'],
                                ];
                                $tc = $typeColors[$account->account_type?->value ?? 'funded'] ?? $typeColors['funded'];
                            @endphp
                            <span style="display:inline-block;padding:0.125rem 0.5rem;border-radius:9999px;font-size:0.75rem;font-weight:500;background:{{ $tc['bg'] }};color:{{ $tc['text'] }}">
                                {{ $account->account_type?->label() ?? 'Funded' }}
                            </span>
                        </td>
                        <td style="padding:0.875rem 1rem;text-align:right;color:#d1d5db">
                            {{ $account->starting_balance !== null ? '$'.number_format((float)$account->starting_balance, 2) : '—' }}
                        </td>
                        <td style="padding:0.875rem 1rem;text-align:right;color:{{ $pnlColor }};font-weight:500">
                            {{ $netPnl >= 0 ? '+' : '' }}${{ number_format(abs($netPnl), 2) }}
                        </td>
                        <td style="padding:0.875rem 1rem;text-align:right;color:#f9fafb;font-weight:500">
                            {{ $balance !== null ? '$'.number_format($balance, 2) : '—' }}
                        </td>
                        <td style="padding:0.875rem 1rem;text-align:right;color:#9ca3af">
                            {{ number_format($account->trades_count) }}
                        </td>
                        <td style="padding:0.875rem 1rem;text-align:right">
                            @if($confirmDeleteId === $account->id)
                            <span style="font-size:0.8125rem;color:#d1d5db;margin-right:0.5rem">Delete?</span>
                            <button wire:click="delete({{ $account->id }})"
                                style="padding:0.25rem 0.625rem;background:#991b1b;color:#fca5a5;border:none;border-radius:0.25rem;font-size:0.75rem;cursor:pointer;margin-right:0.25rem"
                                onmouseover="this.style.background='#7f1d1d'" onmouseout="this.style.background='#991b1b'">
                                Yes
                            </button>
                            <button wire:click="cancelDelete"
                                style="padding:0.25rem 0.625rem;background:#374151;color:#d1d5db;border:none;border-radius:0.25rem;font-size:0.75rem;cursor:pointer"
                                onmouseover="this.style.background='#4b5563'" onmouseout="this.style.background='#374151'">
                                No
                            </button>
                            @else
                            <button wire:click="startEdit({{ $account->id }})"
                                style="padding:0.25rem 0.625rem;background:#1f2937;color:#9ca3af;border:1px solid #374151;border-radius:0.25rem;font-size:0.75rem;cursor:pointer;margin-right:0.25rem"
                                onmouseover="this.style.color='#f9fafb'" onmouseout="this.style.color='#9ca3af'">
                                Edit
                            </button>
                            <button wire:click="confirmDelete({{ $account->id }})"
                                style="padding:0.25rem 0.625rem;background:#1f2937;color:#9ca3af;border:1px solid #374151;border-radius:0.25rem;font-size:0.75rem;cursor:pointer"
                                onmouseover="this.style.color='#f87171'" onmouseout="this.style.color='#9ca3af'">
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
