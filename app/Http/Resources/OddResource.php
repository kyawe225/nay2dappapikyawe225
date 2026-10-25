<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OddResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name,
            'oddNumber' => $this->odd_number, 'startDate' => $this->start_date->toDateString(),
            'payoutMode' => $this->payout_mode,
        ];
    }
}
