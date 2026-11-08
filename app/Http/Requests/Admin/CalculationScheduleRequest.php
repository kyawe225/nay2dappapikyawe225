<?php

namespace App\Http\Requests\Admin;

class CalculationScheduleRequest extends CalculationSettingRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [...parent::rules(), 'drawDate' => 'required|date_format:Y-m-d'];
    }
}
