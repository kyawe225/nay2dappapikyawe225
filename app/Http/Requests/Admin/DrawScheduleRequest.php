<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DrawScheduleRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('calculationTime') && ! $this->has('calculationMode')) {
            $this->merge(['calculationMode' => 'scheduled']);
        }
    }

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
            'timeType' => $this->route('game') === 'threed' ? 'prohibited' : 'required|in:am,pm',
            'openDate' => 'sometimes|date_format:Y-m-d|before_or_equal:drawDate',
            'openTime' => 'required|date_format:H:i',
            'cutoffTime' => 'sometimes|required|date_format:H:i',
            'name' => 'sometimes|nullable|string|max:255',
            'calculationMode' => 'sometimes|required|in:immediate,scheduled,manual',
            'calculationTime' => 'required_if:calculationMode,scheduled|prohibited_unless:calculationMode,scheduled|date_format:H:i',
            'odds' => 'prohibited',
            'oddNumber' => 'prohibited',
            'payoutMode' => 'prohibited',
            'winningNumber' => 'prohibited',
        ];
    }
}
