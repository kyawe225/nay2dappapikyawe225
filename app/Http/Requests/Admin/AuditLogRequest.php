<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AuditLogRequest extends FormRequest
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
            'actorUserId' => 'sometimes|uuid|exists:users,id',
            'requestId' => 'sometimes|uuid',
            'method' => 'sometimes|in:GET,POST,PATCH,PUT,DELETE,HEAD,OPTIONS',
            'statusCode' => 'sometimes|integer|between:100,599',
            'search' => 'sometimes|string|max:255',
            'fromDate' => 'sometimes|date_format:Y-m-d',
            'toDate' => ['sometimes', 'date_format:Y-m-d', ...($this->filled('fromDate') ? ['after_or_equal:fromDate'] : [])],
            'page' => 'sometimes|integer|min:1',
            'perPage' => 'sometimes|integer|min:1|max:100',
        ];
    }
}
