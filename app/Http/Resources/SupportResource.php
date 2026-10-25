<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'value' => $this->value,
            'status' => $this->status,
            'supportTypeId' => $this->support_type_id,
            'supportType' => new SupportTypeResource($this->whenLoaded('supportType')),
        ];
    }
}
