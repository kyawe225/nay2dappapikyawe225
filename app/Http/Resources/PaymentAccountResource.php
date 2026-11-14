<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentAccountResource extends JsonResource
{
    public const RELATIONS = ['paymentMethod', 'user.role'];

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'userName' => $this->user_name,
            'phoneNumber' => $this->phone_number,
            'bankAccountNumber' => $this->bank_account_number,
            'paymentMethod' => new PaymentMethodResource($this->whenLoaded('paymentMethod')),
            'user' => new UserResource($this->whenLoaded('user')),
            'type' => $this->type,
            'status' => $this->status,
            'description' => $this->descriptions,
        ];
    }
}
