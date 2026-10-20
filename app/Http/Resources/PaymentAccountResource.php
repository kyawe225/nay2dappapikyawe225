<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentAccountResource extends JsonResource
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
            'userName' => $this->user_name,
            'phoneNumber' => $this->phone_number,
            'bankAccountNumber' => $this->bank_account_number,
            'description' => $this->descriptions,
        ];
    }
}
