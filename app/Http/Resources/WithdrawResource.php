<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WithdrawResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'referenceId' => $this->reference_id,
            'amount' => $this->amount,
            'requestedPaymentUserId' => $this->requested_payment_user_id,
            'requesterName' => $this->user?->name,
            'requesterEmail' => $this->user?->email,
            'status' => $this->status,
            'approverUserId' => $this->approve_user_id,
            'approvedUserName' => $this->approver?->name,
            'declinedUserId' => $this->declined_user_id,
            'approved_at' => $this->approved_at,
            'declined_at' => $this->declined_at,
            'transaction_id' => $this->transaction_id,
            'requestedPaymentMethodId' => $this->requested_payment_method_id,
            'requestedPaymentAccountId' => $this->requested_payment_account_id,
            'created_at' => $this->created_at,
        ];
    }
}
