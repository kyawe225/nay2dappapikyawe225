<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class WithdrawalRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,50}(\.\d{1,2})?$/'],
            'paymentAccountId' => 'required|uuid|exists:payment_accounts,id',
            'paymentMethodId' => 'required|uuid|exists:payment_methods,id',
            'clientReference' => 'sometimes|required|uuid',
        ];
    }
}
