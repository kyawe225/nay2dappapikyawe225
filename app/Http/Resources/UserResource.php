<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'email' => $this->email, 'status' => $this->status,
            'role' => $this->whenLoaded('role', fn (): array => ['roleName' => $this->role->role_name, 'status' => $this->role->status]),
            'wallet' => new WalletResource($this->whenLoaded('wallet')),
            'currentWalletAmount' => $this->whenLoaded('wallet', fn (): ?string => $this->wallet?->avaliable_balance),
        ];
    }
}
