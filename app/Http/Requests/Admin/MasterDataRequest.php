<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class MasterDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdministrator() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->RulesFor((string) $this->route('resource'));
    }

    /** @return array<string, mixed> */
    protected function RulesFor(string $resource): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes|required';

        return match ($resource) {
            'payment-methods' => [
                'name' => $required.'|string|max:255', 'type' => $required.'|in:deposit,withdraw',
                'description' => $required.'|string|max:500',
                'imageLocation' => 'sometimes|nullable|string|max:255',
                'status' => 'sometimes|in:active,inactive',
            ],
            'support-types' => ['name' => $required.'|string|max:255', 'imageLocation' => $required.'|string|max:255'],
            'supports' => [
                'name' => $required.'|string|max:255', 'supportTypeId' => $required.'|uuid|exists:support_types,id',
                'type' => $required.'|in:link,phone,other', 'value' => 'sometimes|nullable|string|max:700',
                'status' => 'sometimes|in:active,inactive',
            ],
            default => [],
        };
    }
}
