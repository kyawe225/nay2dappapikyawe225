<?php

namespace App\Models;

use Database\Factories\ThreedDrawFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'draw_date', 'open_date', 'open_time', 'cutoff_time', 'winning_number', 'calculation_status', 'calculation_mode', 'calculation_time', 'calculation_scheduled_at', 'result_revision', 'calculated_at', 'settled_at', 'cancelled_at', 'recorded_by', 'settled_by', 'cancelled_by', 'cancellation_reason'])]
class ThreedDraw extends Model
{
    /** @use HasFactory<ThreedDrawFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['draw_date' => 'date', 'open_date' => 'date', 'winning_number' => 'string', 'calculation_scheduled_at' => 'immutable_datetime', 'calculated_at' => 'datetime', 'settled_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }
}
