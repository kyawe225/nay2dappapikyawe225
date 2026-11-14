<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletTransactionResource extends JsonResource
{
    public const RELATIONS = ['wallet.user.role', 'actor.role'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'wallet' => new WalletResource($this->whenLoaded('wallet')),
            'type' => $this->type,
            'direction' => $this->direction,
            'amount' => $this->amount,
            'balanceBefore' => $this->balance_before,
            'balanceAfter' => $this->balance_after,
            'referenceType' => $this->reference_type,
            'referenceId' => $this->reference_id,
            'actorUser' => new UserResource($this->whenLoaded('actor')),
            'reason' => $this->reason,
            'clientReference' => $this->client_reference,
            'created_at' => $this->created_at,
        ];
    }
}
