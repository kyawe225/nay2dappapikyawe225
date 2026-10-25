<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletTransactionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'walletId' => $this->wallet_id,
            'type' => $this->type,
            'direction' => $this->direction,
            'amount' => $this->amount,
            'balanceBefore' => $this->balance_before,
            'balanceAfter' => $this->balance_after,
            'referenceType' => $this->reference_type,
            'referenceId' => $this->reference_id,
            'actorUserId' => $this->actor_user_id,
            'reason' => $this->reason,
            'clientReference' => $this->client_reference,
            'created_at' => $this->created_at,
        ];
    }
}
