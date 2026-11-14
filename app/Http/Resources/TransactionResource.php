<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    public const RELATIONS = ['user.role', 'paymentAccount.paymentMethod', 'paymentAccount.user.role', 'paymentMethod', 'approver.role', 'decliner.role'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'referenceId' => $this->reference_id,
            'paymentUser' => new UserResource($this->whenLoaded('user')),
            'requesterName' => $this->user?->name,
            'requesterEmail' => $this->user?->email,
            'amount' => $this->amount,
            'paid_at' => $this->paid_at,
            'lastSixTranId' => $this->last_six_tran_id,
            'paymentAccount' => new PaymentAccountResource($this->whenLoaded('paymentAccount')),
            'paymentMethod' => new PaymentMethodResource($this->whenLoaded('paymentMethod')),
            'approver' => new UserResource($this->whenLoaded('approver')),
            'approvedUserName' => $this->approver?->name,
            'approved_at' => $this->approved_at,
            'decliner' => new UserResource($this->whenLoaded('decliner')),
            'declined_at' => $this->declined_at,
            'status' => $this->status,
            'created_at' => $this->created_at,
        ];
    }
}
