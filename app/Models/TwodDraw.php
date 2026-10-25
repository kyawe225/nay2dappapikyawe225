<?php

namespace App\Models;

use Database\Factories\TwodDrawFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'draw_date', 'time_type', 'open_time', 'cutoff_time', 'winning_number', 'calculation_status', 'result_revision', 'calculated_at', 'settled_at', 'cancelled_at', 'recorded_by', 'settled_by', 'cancelled_by', 'cancellation_reason'])]
class TwodDraw extends Model
{
    /** @use HasFactory<TwodDrawFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['draw_date' => 'date', 'winning_number' => 'string', 'calculated_at' => 'datetime', 'settled_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }
}
