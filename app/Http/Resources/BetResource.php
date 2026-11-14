<?php

namespace App\Http\Resources;

use App\Models\TwodEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BetResource extends JsonResource
{
    public const RELATIONS = ['user.role'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'batchId' => $this->batch_id,
            'clientReference' => $this->client_reference, 'entryIndex' => (int) $this->entry_index,
            'drawDate' => $this->draw_date->toDateString(),
            'timeType' => $this->when($this->resource instanceof TwodEntry, fn (): ?string => $this->time),
            'number' => $this->draw_number,
            'user' => new UserResource($this->whenLoaded('user')),
            'stakeAmount' => $this->stake_amount, 'acceptedOdds' => $this->accepted_odds,
            'potentialPayout' => $this->potential_payout, 'settleAmount' => $this->settle_amount,
            'status' => $this->status, 'resultStatus' => $this->result_status,
            'calculated_at' => $this->calculated_at, 'settled_at' => $this->settled_at,
            'placed_at' => $this->created_at,
        ];
    }
}
