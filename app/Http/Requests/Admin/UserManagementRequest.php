<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserManagementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdministrator() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => $this->isMethod('POST') ? 'required|string|max:255' : 'sometimes|required|string|max:255',
            'email' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('account'))],
            'password' => $this->isMethod('POST') ? 'required|string|min:8|max:255' : 'prohibited',
            'roleId' => ['sometimes', 'required', 'uuid', Rule::exists('roles', 'id')->where('status', 'active')->whereNull('deleted_at')],
            'status' => 'sometimes|required|in:active,suspended',
        ];
    }
}
