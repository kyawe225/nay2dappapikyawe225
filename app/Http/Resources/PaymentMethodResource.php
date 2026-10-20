<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentMethodResource extends JsonResource
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
            'name' => $this->name,
            'paymentMethodImage' => $this->payment_method_image_location,
            'type' => $this->type,
            'description' => $this->description,
            'created_at'=>$this->type != "deposit" ? $this->created_at : null,
            'paymentAccounts'=> $this->type != "withdraw" ? PaymentAccountResource::collection($this->paymentAccounts) : null
        ];
    }
}
