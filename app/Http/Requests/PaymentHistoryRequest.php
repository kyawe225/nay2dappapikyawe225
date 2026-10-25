<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PaymentHistoryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => 'sometimes|in:pending,pending_approval,approved,rejected,requested,declined',
            'paymentUserId' => 'sometimes|uuid|exists:users,id',
            'fromDate' => 'sometimes|date_format:Y-m-d',
            'toDate' => ['sometimes', 'date_format:Y-m-d', ...($this->filled('fromDate') ? ['after_or_equal:fromDate'] : [])],
            'page' => 'sometimes|integer|min:1',
            'perPage' => 'sometimes|integer|min:1|max:100',
        ];
    }
}
