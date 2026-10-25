<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class OddRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdministrator() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'oddNumber' => ['required', 'numeric', 'gt:0', 'regex:/^\\d{1,16}(\\.\\d{1,4})?$/'],
            'startDate' => 'sometimes|date_format:Y-m-d',
            'payoutMode' => 'required|in:total,profit',
        ];
    }
}
