<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class DrawRequest extends FormRequest
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
            'timeType' => $this->route('game') === 'threed' ? 'prohibited' : 'required|in:am,pm',
            'winningNumber' => ['required', 'string', $this->route('game') === 'twod' ? 'regex:/^\\d{2}$/' : 'regex:/^\\d{3}$/'],
            'name' => 'sometimes|nullable|string|max:255',
            'calculationMode' => 'sometimes|required|in:immediate,scheduled,manual',
            'calculationTime' => 'required_if:calculationMode,scheduled|prohibited_unless:calculationMode,scheduled|date_format:H:i',
            'odds' => 'prohibited',
            'oddNumber' => 'prohibited',
            'payoutMode' => 'prohibited',
            'openTime' => 'prohibited',
            'cutoffTime' => 'prohibited',
        ];
    }
}
