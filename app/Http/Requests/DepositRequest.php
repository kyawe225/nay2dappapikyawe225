<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DepositRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'lastSixTranId' => 'required|string|size:6',
            'amount' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,50}(\.\d{1,2})?$/'],
            'PaymentAccountId' => 'required|uuid|exists:payment_accounts,id',
            'PaymentMethodId' => 'required|uuid|exists:payment_methods,id',
            'clientReference' => 'sometimes|required|uuid',
        ];
    }
}
