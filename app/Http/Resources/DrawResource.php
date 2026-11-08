<?php

namespace App\Http\Resources;

use App\Models\TwodDraw;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DrawResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name,
            'drawDate' => $this->draw_date->toDateString(),
            'timeType' => $this->when($this->resource instanceof TwodDraw, fn (): ?string => $this->time_type),
            'openDate' => ($this->open_date ?? $this->draw_date)->toDateString(),
            'openTime' => $this->open_time, 'cutoffTime' => $this->cutoff_time,
            'winningNumber' => $this->winning_number, 'calculationStatus' => $this->calculation_status,
            'calculationMode' => $this->calculation_mode, 'calculationTime' => $this->calculation_time,
            'calculationScheduledAt' => $this->calculation_scheduled_at,
            'timezone' => config('betting.timezone'),
            'calculated_at' => $this->calculated_at, 'settled_at' => $this->settled_at,
            'cancelled_at' => $this->cancelled_at, 'cancellationReason' => $this->cancellation_reason,
        ];
    }
}
