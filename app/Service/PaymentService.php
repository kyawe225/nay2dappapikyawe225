<?php

namespace App\Service;

use App\Models\PaymentMethod;
use App\Models\PaymentTransactions;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PaymentService
{
    /**
     * Amounts and balances are whole MMK units.
     *
     * @param  array{payment_method_id: int|string, type: string, amount: int|string, client_reference?: ?string, account_name?: ?string, account_number?: ?string, reference_number?: ?string, note?: ?string}  $data
     */
    public function create(User $user, array $data): PaymentTransactions
    {
        return DB::transaction(function () use ($user, $data): PaymentTransactions {
            $walletOwner = User::query()->lockForUpdate()->findOrFail($user->id);

            if (! empty($data['client_reference'])) {
                $existingPayment = $walletOwner->payments()->where('client_reference', $data['client_reference'])->first();

                if ($existingPayment !== null) {
                    $this->ensureMatchingRequest($existingPayment, $data);

                    return $existingPayment->load('paymentMethod');
                }
            }

            $paymentMethod = PaymentMethod::query()->where('status', 'active')
                ->lockForUpdate()->find($data['payment_method_id']);

            if ($paymentMethod === null) {
                throw ValidationException::withMessages(['payment_method_id' => 'This payment method is unavailable.']);
            }

            if ($data['type'] === 'deposit' && PaymentTransactions::query()
                ->where('payment_method_id', $paymentMethod->id)
                ->where('reference_number', $data['reference_number'])->exists()) {
                throw ValidationException::withMessages(['reference_number' => 'This payment reference has already been submitted.']);
            }

            $amount = (int) $data['amount'];

            if ($data['type'] === 'withdrawal' && $walletOwner->balance < $amount) {
                throw ValidationException::withMessages(['amount' => 'Insufficient wallet balance.']);
            }

            $payment = PaymentTransactions::query()->create([
                'user_id' => $walletOwner->id,
                'payment_method_id' => $paymentMethod->id,
                'client_reference' => $data['client_reference'] ?? null,
                'type' => $data['type'],
                'amount' => $amount,
                'status' => 'pending',
                'account_name' => $data['type'] === 'deposit' ? $paymentMethod->account_name : $data['account_name'],
                'account_number' => $data['type'] === 'deposit' ? $paymentMethod->account_number : $data['account_number'],
                'reference_number' => $data['type'] === 'deposit' ? $data['reference_number'] : null,
                'note' => $data['note'] ?? null,
            ]);

            if ($payment->type === 'withdrawal') {
                $this->recordBalanceChange($walletOwner, $payment, 'withdrawal');
            }

            return $payment->load('paymentMethod');
        }, 3);
    }

    public function review(PaymentTransactions $payment, User $reviewer, string $status, ?string $note): PaymentTransactions
    {
        abort_unless($reviewer->isAdministrator(), 403);

        return DB::transaction(function () use ($payment, $reviewer, $status, $note): PaymentTransactions {
            $walletOwner = User::query()->lockForUpdate()->findOrFail($payment->user_id);
            $lockedPayment = PaymentTransactions::query()->lockForUpdate()->findOrFail($payment->id);

            if ($lockedPayment->status === $status) {
                return $lockedPayment->load('paymentMethod');
            }

            if ($lockedPayment->status !== 'pending') {
                throw new ConflictHttpException('Only pending payments can be reviewed.');
            }

            if (! in_array($status, ['approved', 'rejected'], true)) {
                throw ValidationException::withMessages(['status' => 'Choose approved or rejected.']);
            }

            if ($lockedPayment->type === 'deposit' && $status === 'approved') {
                $this->recordBalanceChange($walletOwner, $lockedPayment, 'deposit');
            }

            if ($lockedPayment->type === 'withdrawal' && $status === 'rejected') {
                $this->recordBalanceChange($walletOwner, $lockedPayment, 'withdrawal_refund');
            }

            $lockedPayment->update([
                'status' => $status,
                'review_note' => $note,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ]);

            return $lockedPayment->load('paymentMethod');
        }, 3);
    }

    public function cancel(PaymentTransactions $payment, User $user): PaymentTransactions
    {
        abort_unless($payment->user_id === $user->id, 404);

        return DB::transaction(function () use ($payment, $user): PaymentTransactions {
            $walletOwner = User::query()->lockForUpdate()->findOrFail($user->id);
            $lockedPayment = PaymentTransactions::query()->where('user_id', $user->id)
                ->lockForUpdate()->findOrFail($payment->id);

            if ($lockedPayment->status === 'cancelled') {
                return $lockedPayment->load('paymentMethod');
            }

            if ($lockedPayment->status !== 'pending') {
                throw new ConflictHttpException('Only pending payments can be cancelled.');
            }

            if ($lockedPayment->type === 'withdrawal') {
                $this->recordBalanceChange($walletOwner, $lockedPayment, 'withdrawal_refund');
            }

            $lockedPayment->update(['status' => 'cancelled']);

            return $lockedPayment->load('paymentMethod');
        }, 3);
    }

    private function recordBalanceChange(User $user, PaymentTransactions $payment, string $type): void
    {
        $balanceBefore = $user->balance;

        if ($type === 'deposit') {
            $reservedBalance = (int) $user->payments()->where('type', 'withdrawal')->where('status', 'pending')->sum('amount');

            if ($payment->amount > 9007199254740991 - $balanceBefore - $reservedBalance) {
                throw ValidationException::withMessages(['amount' => 'This amount exceeds the supported wallet balance.']);
            }
        }

        $balanceAfter = $type === 'withdrawal' ? $balanceBefore - $payment->amount : $balanceBefore + $payment->amount;

        if ($balanceAfter < 0 || $balanceAfter > 9007199254740991) {
            throw ValidationException::withMessages(['amount' => 'This amount exceeds the supported wallet balance.']);
        }

        $user->balance = $balanceAfter;
        $user->save();

        Transaction::query()->create([
            'user_id' => $user->id,
            'payment_transaction_id' => $payment->id,
            'type' => $type,
            'amount' => $payment->amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
        ]);
    }

    /** @param array{payment_method_id: int|string, type: string, amount: int|string, client_reference?: ?string, account_name?: ?string, account_number?: ?string, reference_number?: ?string, note?: ?string} $data */
    private function ensureMatchingRequest(PaymentTransactions $payment, array $data): void
    {
        $matches = $payment->payment_method_id === (int) $data['payment_method_id']
            && $payment->type === $data['type']
            && $payment->amount === (int) $data['amount']
            && $payment->note === ($data['note'] ?? null);

        if ($payment->type === 'withdrawal') {
            $matches = $matches && $payment->account_name === $data['account_name']
                && $payment->account_number === $data['account_number'];
        } else {
            $matches = $matches && $payment->reference_number === $data['reference_number'];
        }

        if (! $matches) {
            throw new ConflictHttpException('This client reference was already used for a different payment.');
        }
    }
}
