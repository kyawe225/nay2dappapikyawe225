<?php

namespace App\Http\Requests\Web;

use Illuminate\Foundation\Http\FormRequest;

class BetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && ! $this->user()->isAdministrator();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'drawDate' => 'required|date_format:Y-m-d',
            'timeType' => 'required|in:am,pm',
            'clientReference' => 'required|uuid',
            'entries' => 'required|array|list|min:1|max:1000',
            'entries.*' => 'required|array:number,stakeAmount',
            'entries.*.number' => ['required', 'string', $this->route('game') === 'twod' ? 'regex:/\A[0-9]{2}\z/' : 'regex:/\A[0-9]{3}\z/'],
            'entries.*.stakeAmount' => ['required', 'numeric', 'gt:0', 'regex:/\A[0-9]{1,50}(\.[0-9]{1,2})?\z/'],
        ];
    }
}
