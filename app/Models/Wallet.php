<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'avaliable_balance', 'locked_balance', 'version'])]
class Wallet extends Model
{
    use HasUuids;

    protected $table = 'wallet';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['avaliable_balance' => 'decimal:2', 'locked_balance' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'payment_user_id', 'user_id');
    }

    public function withdrawalRequests(): HasMany
    {
        return $this->hasMany(WithdrawRequest::class, 'requested_payment_user_id', 'user_id');
    }
}
