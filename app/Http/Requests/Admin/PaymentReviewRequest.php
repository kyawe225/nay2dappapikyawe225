<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PaymentReviewRequest extends FormRequest
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
            'status' => $this->route('withdrawal') !== null ? 'required|in:approved,declined' : 'required|in:approved,rejected',
            'lastSixTranId' => $this->route('withdrawal') !== null && $this->input('status') === 'approved' ? 'required|string|size:6' : 'sometimes|string|size:6',
        ];
    }
}
