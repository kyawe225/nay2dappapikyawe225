<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DrawScheduleRequest extends FormRequest
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
            'drawDate' => 'required|date_format:Y-m-d',
            'timeType' => 'required|in:am,pm',
            'openTime' => 'required|date_format:H:i',
            'cutoffTime' => 'required|date_format:H:i|after:openTime',
            'name' => 'sometimes|nullable|string|max:255',
            'odds' => 'prohibited',
            'oddNumber' => 'prohibited',
            'payoutMode' => 'prohibited',
            'winningNumber' => 'prohibited',
        ];
    }
}
