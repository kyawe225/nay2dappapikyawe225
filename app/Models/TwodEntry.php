<?php

namespace App\Models;

use Database\Factories\TwodEntriesFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'draw_date', 'time', 'draw_number', 'status', 'user_id', 'stake_amount', 'potential_payout', 'settle_amount'])]
#[UseFactory(TwodEntriesFactory::class)]
class TwodEntry extends Model
{
    /** @use HasFactory<TwodEntriesFactory> */
    use HasFactory, HasUuids;

    protected $table = 'twod_bets';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'draw_date' => 'date',
            'draw_number' => 'string',
            'stake_amount' => 'decimal:2',
            'potential_payout' => 'decimal:2',
            'settle_amount' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
