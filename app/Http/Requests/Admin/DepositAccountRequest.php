<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DepositAccountRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdministrator() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'userName' => 'required|string|max:255',
            'phoneNumber' => 'nullable|required_without:bankAccountNumber|string|max:255',
            'bankAccountNumber' => 'nullable|required_without:phoneNumber|string|max:255',
            'descriptions' => 'nullable|string|max:255',
            'paymentMethodId' => ['required', 'uuid', Rule::exists('payment_methods', 'id')->where('type', 'deposit')->where('status', 'active')],
        ];
    }
}
