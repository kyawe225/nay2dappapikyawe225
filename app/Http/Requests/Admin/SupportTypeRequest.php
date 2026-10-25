<?php

namespace App\Http\Requests\Admin;

class SupportTypeRequest extends MasterDataRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->RulesFor('support-types');
    }
}
