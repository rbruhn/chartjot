<?php

namespace App\Models;

use App\Enums\TransactionType;
use Database\Factories\AccountTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'type', 'amount', 'occurred_at'])]
class AccountTransaction extends BaseModel
{
    /** @use HasFactory<AccountTransactionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type'        => TransactionType::class,
            'amount'      => 'decimal:2',
            'occurred_at' => 'date',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
