<?php

namespace App\Models;

use Database\Factories\ThreedDrawFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'draw_date', 'time_type', 'open_time', 'cutoff_time', 'winning_number', 'odds'])]
class ThreedDraw extends Model
{
    /** @use HasFactory<ThreedDrawFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['draw_date' => 'date', 'winning_number' => 'string', 'odds' => 'decimal:2'];
    }
}
