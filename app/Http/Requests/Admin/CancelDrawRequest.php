<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CancelDrawRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdministrator() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'drawDate' => 'required|date_format:Y-m-d',
            'timeType' => 'required|in:am,pm',
            'reason' => 'required|string|max:700',
        ];
    }
}
