<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class WalletManagementRequest extends FormRequest
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
        $adjustment = $this->isMethod('PATCH');

        return [
            'reason' => 'required|string|max:700',
            'amount' => $adjustment ? ['required', 'numeric', 'gt:0', 'regex:/\A[0-9]{1,50}(\.[0-9]{1,2})?\z/'] : 'prohibited',
            'direction' => $adjustment ? 'required|in:in,out' : 'prohibited',
            'clientReference' => $adjustment ? 'required|uuid' : 'prohibited',
            'avaliableBalance' => 'prohibited',
            'lockedBalance' => 'prohibited',
            'avaliable_balance' => 'prohibited',
            'locked_balance' => 'prohibited',
            'userId' => 'prohibited',
            'version' => 'prohibited',
        ];
    }
}
