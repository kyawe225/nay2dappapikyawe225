<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GameHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'drawDate' => 'sometimes|date_format:Y-m-d',
            'timeType' => 'sometimes|in:am,pm',
            'status' => 'sometimes|in:active,settled,cancelled,lost',
            'resultStatus' => 'sometimes|in:pending,won,lost',
            'paymentUserId' => 'sometimes|uuid|exists:users,id',
            'page' => 'sometimes|integer|min:1',
            'perPage' => 'sometimes|integer|min:1|max:100',
        ];
    }
}
