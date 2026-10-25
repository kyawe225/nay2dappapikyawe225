<?php

namespace App\Http\Requests\Admin;

class PaymentMethodRequest extends MasterDataRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->RulesFor('payment-methods');
    }
}
