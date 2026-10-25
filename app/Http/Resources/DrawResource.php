<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DrawResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name,
            'drawDate' => $this->draw_date->toDateString(), 'timeType' => $this->time_type,
            'winningNumber' => $this->winning_number, 'calculationStatus' => $this->calculation_status,
            'calculated_at' => $this->calculated_at, 'settled_at' => $this->settled_at,
            'cancelled_at' => $this->cancelled_at, 'cancellationReason' => $this->cancellation_reason,
        ];
    }
}
