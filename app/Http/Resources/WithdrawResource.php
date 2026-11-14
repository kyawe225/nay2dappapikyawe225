<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WithdrawResource extends JsonResource
{
    public const RELATIONS = ['user.role', 'approver.role', 'decliner.role', 'transaction.user.role', 'transaction.paymentAccount.paymentMethod', 'transaction.paymentAccount.user.role', 'transaction.paymentMethod', 'transaction.approver.role', 'transaction.decliner.role', 'paymentMethod', 'paymentAccount.paymentMethod', 'paymentAccount.user.role'];

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
            'requestedPaymentUser' => new UserResource($this->whenLoaded('user')),
            'requesterName' => $this->user?->name,
            'requesterEmail' => $this->user?->email,
            'status' => $this->status,
            'approver' => new UserResource($this->whenLoaded('approver')),
            'approvedUserName' => $this->approver?->name,
            'decliner' => new UserResource($this->whenLoaded('decliner')),
            'approved_at' => $this->approved_at,
            'declined_at' => $this->declined_at,
            'transaction' => new TransactionResource($this->whenLoaded('transaction')),
            'requestedPaymentMethod' => new PaymentMethodResource($this->whenLoaded('paymentMethod')),
            'requestedPaymentAccount' => new PaymentAccountResource($this->whenLoaded('paymentAccount')),
            'requested_at' => $this->created_at,
        ];
    }
}
