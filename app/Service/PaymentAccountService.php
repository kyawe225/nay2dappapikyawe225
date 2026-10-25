<?php

namespace App\Service;

use App\Http\Requests\Admin\DepositAccountRequest;
use App\Http\Requests\Admin\PaymentAccountStatusRequest;
use App\Http\Requests\DepositRequest;
use App\Http\Requests\WithdrawalAccountRequest;
use App\Http\Requests\WithdrawalRequest;
use App\Http\Resources\PaymentAccountResource;
use App\Http\ServiceContractor\IPaymentAccountService;
use App\Http\ServiceContractor\IPaymentService;
use App\Models\PaymentAccount;
use App\Models\User;
use App\Response\ResponseModel;
use Illuminate\Support\Facades\DB;

class PaymentAccountService implements IPaymentAccountService
{
    public function __construct(private IPaymentService $paymentService) {}

    public function CreateNewWithdrawalAccount(WithdrawalAccountRequest $request, User $user): ResponseModel
    {
        return $this->CreateAccount($request->validated(), $user, 'withdraw');
    }

    public function CreateNewDepositAccount(DepositAccountRequest $request, User $user): ResponseModel
    {
        return $this->CreateAccount($request->validated(), $user, 'deposit');
    }

    public function CreateNewWithdrawalRequest(WithdrawalRequest $request, User $user): ResponseModel
    {
        return $this->paymentService->CreateNewWithdrawalRequest($request, $user);
    }

    public function CreateNewDepositRequest(DepositRequest $request, User $user): ResponseModel
    {
        return $this->paymentService->CreateNewDepositRequest($request, $user);
    }

    public function WithdrawalAccounts(User $user): ResponseModel
    {
        $accounts = $user->paymentAccounts()->with('paymentMethod')->where('type', 'withdraw')->where('status', '!=', 'delete')->latest()->latest('id')->get();

        return ResponseModel::ok('Withdrawal accounts fetched successfully!', PaymentAccountResource::collection($accounts));
    }

    public function DepositAccounts(): ResponseModel
    {
        $accounts = PaymentAccount::query()->with('paymentMethod')->where('type', 'deposit')->where('status', '!=', 'delete')->latest()->latest('id')->get();

        return ResponseModel::ok('Deposit accounts fetched successfully!', PaymentAccountResource::collection($accounts));
    }

    public function DeleteWithdrawalAccount(string $accountId, User $user): ResponseModel
    {
        return DB::transaction(function () use ($accountId, $user): ResponseModel {
            User::query()->lockForUpdate()->findOrFail($user->id);
            $account = $user->paymentAccounts()->where('type', 'withdraw')->lockForUpdate()->find($accountId);

            if ($account === null) {
                return ResponseModel::NotFound('Withdrawal account was not found!', null);
            }

            $account->status = 'delete';
            $account->save();

            return ResponseModel::ok('Withdrawal account deleted successfully!', null);
        }, 3);
    }

    public function ChangeDepositAccountStatus(PaymentAccountStatusRequest $request, string $accountId): ResponseModel
    {
        return DB::transaction(function () use ($request, $accountId): ResponseModel {
            $account = PaymentAccount::query()->where('type', 'deposit')->where('status', '!=', 'delete')->lockForUpdate()->find($accountId);

            if ($account === null) {
                return ResponseModel::NotFound('Deposit account was not found!', null);
            }

            $account->status = $request->validated('status');
            $account->save();

            return ResponseModel::ok('Deposit account status changed successfully!', new PaymentAccountResource($account));
        }, 3);
    }

    /** @param array{userName: string, paymentMethodId: string, phoneNumber?: ?string, bankAccountNumber?: ?string, descriptions?: ?string} $validatedArr */
    private function CreateAccount(array $validatedArr, User $user, string $type): ResponseModel
    {
        return DB::transaction(function () use ($validatedArr, $user, $type): ResponseModel {
            User::query()->lockForUpdate()->findOrFail($user->id);
            $existingAccount = $user->paymentAccounts()->where('type', $type)
                ->where('payment_method_id', $validatedArr['paymentMethodId'])
                ->where('phone_number', $validatedArr['phoneNumber'] ?? null)
                ->where('bank_account_number', $validatedArr['bankAccountNumber'] ?? null)
                ->where('status', '!=', 'delete')->first();

            if ($existingAccount !== null) {
                return ResponseModel::conflict('Duplicate payment account! Please add a new account!', null);
            }

            $account = PaymentAccount::create([
                'type' => $type,
                'user_name' => $validatedArr['userName'],
                'phone_number' => $validatedArr['phoneNumber'] ?? null,
                'bank_account_number' => $validatedArr['bankAccountNumber'] ?? null,
                'descriptions' => $validatedArr['descriptions'] ?? null,
                'payment_method_id' => $validatedArr['paymentMethodId'],
                'user_id' => $user->id,
                'status' => 'active',
            ]);

            return ResponseModel::ok('Payment account created successfully!', new PaymentAccountResource($account));
        }, 3);
    }
}
