<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SettlementRequest extends FormRequest
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
            'previewToken' => $this->routeIs('admin.betting.settle') ? 'required|string|size:64' : 'sometimes|string|size:64',
        ];
    }
}
