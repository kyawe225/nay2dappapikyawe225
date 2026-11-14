<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletResource extends JsonResource
{
    public const RELATIONS = ['user.role'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => new UserResource($this->whenLoaded('user')),
            'avaliableBalance' => $this->avaliable_balance, 'lockedBalance' => $this->locked_balance,
            'paymentTransactions' => TransactionResource::collection($this->whenLoaded('paymentTransactions')),
            'withdrawalRequests' => WithdrawResource::collection($this->whenLoaded('withdrawalRequests')),
            'walletTransactions' => WalletTransactionResource::collection($this->whenLoaded('transactions')),
            'version' => $this->version, 'created_at' => $this->created_at,
        ];
    }
}
