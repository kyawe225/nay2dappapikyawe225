<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'referenceId' => $this->reference_id,
            'paymentUserId' => $this->payment_user_id,
            'requesterName' => $this->user?->name,
            'requesterEmail' => $this->user?->email,
            'amount' => $this->amount,
            'paid_at' => $this->paid_at,
            'lastSixTranId' => $this->last_six_tran_id,
            'paymentAccountId' => $this->payment_accounts_id,
            'paymentMethodId' => $this->payment_method_id,
            'approverUserId' => $this->approver_user_id,
            'approvedUserName' => $this->approver?->name,
            'approved_at' => $this->approved_at,
            'declinedUserId' => $this->declined_user_id,
            'declined_at' => $this->declined_at,
            'status' => $this->status,
            'created_at' => $this->created_at,
        ];
    }
}
