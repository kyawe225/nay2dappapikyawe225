<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class WalletHistoryRequest extends FormRequest
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
            'fromDate' => 'sometimes|date_format:Y-m-d',
            'toDate' => ['sometimes', 'date_format:Y-m-d', ...($this->filled('fromDate') ? ['after_or_equal:fromDate'] : [])],
            'perPage' => 'sometimes|integer|min:1|max:100',
            'transactionsPage' => 'sometimes|integer|min:1',
            'withdrawalRequestsPage' => 'sometimes|integer|min:1',
            'walletTransactionsPage' => 'sometimes|integer|min:1',
            'transactionType' => 'sometimes|in:deposit,withdraw',
            'transactionStatus' => 'sometimes|in:pending,pending_approval,approved,rejected',
            'withdrawalStatus' => 'sometimes|in:requested,approved,declined',
            'movementType' => 'sometimes|in:deposit,bet_placed,bet_won,bet_refunded,withdraw_lock,withdraw_rejected,withdraw_requested,withdraw_failed,manual_adjustment',
            'direction' => 'sometimes|in:in,out',
            'referenceType' => 'sometimes|string|max:50',
            'referenceId' => 'sometimes|string|max:50',
        ];
    }
}
