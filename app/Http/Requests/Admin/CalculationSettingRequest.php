<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CalculationSettingRequest extends FormRequest
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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'timeType' => $this->route('game') === 'threed' ? 'prohibited' : 'required|in:am,pm',
            'calculationMode' => 'required|in:immediate,scheduled,manual',
            'calculationTime' => 'required_if:calculationMode,scheduled|prohibited_unless:calculationMode,scheduled|date_format:H:i',
        ];
    }
}
