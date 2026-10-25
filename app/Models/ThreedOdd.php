<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'odd_number', 'start_date', 'payout_mode'])]
class ThreedOdd extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'threed_odds';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['odd_number' => 'decimal:4', 'start_date' => 'date'];
    }
}
