<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'userId' => $this->user_id,
            'avaliableBalance' => $this->avaliable_balance, 'lockedBalance' => $this->locked_balance,
            'version' => $this->version, 'created_at' => $this->created_at, 'updated_at' => $this->updated_at,
        ];
    }
}
