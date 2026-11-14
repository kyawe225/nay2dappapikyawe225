<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdminListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdministrator() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => 'sometimes|string|max:255',
            'status' => 'sometimes|in:active,inactive,suspended',
            'roleName' => 'sometimes|string|exists:roles,role_name',
            'page' => 'sometimes|integer|min:1',
            'paymentsPage' => 'sometimes|integer|min:1',
            'withdrawalsPage' => 'sometimes|integer|min:1',
            'movementsPage' => 'sometimes|integer|min:1',
            'perPage' => 'sometimes|integer|min:1|max:100',
        ];
    }
}
