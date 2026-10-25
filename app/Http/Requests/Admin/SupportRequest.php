<?php

namespace App\Http\Requests\Admin;

class SupportRequest extends MasterDataRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->RulesFor('supports');
    }
}
