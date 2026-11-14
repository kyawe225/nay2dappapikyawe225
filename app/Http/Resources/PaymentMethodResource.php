<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

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
            'paymentMethodImage' => $this->payment_method_image_location === null ? null : Storage::disk('public')->url($this->payment_method_image_location),
            'type' => $this->type,
            'status' => $this->status,
            'description' => $this->description,
            'paymentAccounts' => $this->type != 'withdraw' ? PaymentAccountResource::collection($this->whenLoaded('paymentAccounts')) : null,
        ];
    }
}
