<?php

namespace App\Service;

use App\Http\Requests\Admin\DepositAccountRequest;
use App\Http\Requests\DepositRequest;
use App\Http\Requests\WithdrawalAccountRequest;
use App\Http\Requests\WithdrawalRequest;
use App\Models\WithdrawRequest as WithdrawalRequestModel;
use App\Http\ServiceContractor\IPaymentAccountService;
use App\Models\PaymentAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WithdrawRequest;
use App\Response\ResponseModel;
use Carbon\Carbon;
use Exception;
use Illuminate\Log\Logger;
use Str;

class PaymentAccountService implements IPaymentAccountService
{

    public function __construct(private Logger $logger)
    {
        $this->logger = $logger;
    }
    public function CreateNewWithdrawalAccount(WithdrawalAccountRequest $request, User $user): ResponseModel
    {
        try {
            $validatedArr = $request->validated();
            if (
                PaymentAccount::where("type", "withdraw")->where(function ($query) use ($validatedArr) {
                    $query->where("bank_account_number", $validatedArr["bankAccountNumber"])->Where('phone_number', $validatedArr["phoneNumber"]);
                })->exists()
            ) {
                return ResponseModel::ok("Duplicate withdrawal account please add new account!", null);
            }

            $arr = [
                "type" => "withdraw",
                "user_name" => $validatedArr['userName'],
                "phone_number" => $validatedArr["phoneNumber"],
                "bank_account_number" => $validatedArr["bankAccountNumber"],
                "descriptions" => $validatedArr["descriptions"],
                "payment_method_id" => $validatedArr["paymentMethodId"],
                "user_id" => $user->id
            ];

            $account = PaymentAccount::create($arr);
            $account->save();

            return ResponseModel::ok("Created withdrawal account successfully!", null);
        } catch (Exception $ex) {
            dd($ex);
            $this->logger->error($ex->getMessage());
            return ResponseModel::badRequest("Withdrawal request creation failed!", null);
        }
    }
    public function CreateNewWithdrawalRequest(WithdrawalRequest $request, User $user): ResponseModel
    {
        try {
            $validatedArr = $request->validated();
            $arr = [
                "reference_id" => Str::random(10),
                "requested_payment_user_id" => $user->id,
                "amount" => $validatedArr["amount"],
                "requested_payment_account_id" => $validatedArr["paymentAccountId"],
                "requested_payment_method_id" => $validatedArr["paymentMethodId"],
                "status" => "requested"
            ];
            $transaction = WithdrawalRequestModel::create($arr);
            $transaction->save();

            return ResponseModel::ok("Withdrawal Requested Successfully! Please wait for admin approval!!", null);
        } catch (Exception $ex) {
            $this->logger->error($ex->getMessage());
            return ResponseModel::internalServer("Withdrawal request creation failed!", null);
        }
    }
    public function CreateNewDepositAccount(DepositAccountRequest $request, User $user): ResponseModel
    {
        try {
            $validatedArr = $request->validated();
            if (
                PaymentAccount::where("type", "deposit")->where(function ($query) use ($validatedArr) {
                    $query->where("bank_account_number", $validatedArr["bankAccountNumber"])->Where('phone_number', $validatedArr["phoneNumber"]);
                })->exists()
            ) {
                return ResponseModel::ok("Duplicate deposit account please add new account!", null);
            }
            $arr = [
                "type" => "deposit",
                "user_name" => $validatedArr['userName'],
                "phone_number" => $validatedArr["phoneNumber"],
                "bank_account_number" => $validatedArr["bankAccountNumber"],
                "descriptions" => $validatedArr["descriptions"],
                "payment_method_id" => $validatedArr["paymentMethodId"],
                "user_id" => $user->id
            ];

            $account = PaymentAccount::create($arr);
            $account->save();

            return ResponseModel::ok("Created deposit account successfully!", null);
        } catch (Exception $ex) {
            $this->logger->error($ex->getMessage());
            return ResponseModel::internalServer("Deposit account creation failed!", null);
        }
    }
    public function CreateNewDepositRequest(DepositRequest $request, User $user): ResponseModel
    {
        try {
            $validatedArr = $request->validated();
            $arr = [
                "reference_id" => Str::random(10),
                "payment_user_id" => $user->id,
                "amount" => $validatedArr["amount"],
                "paid_at" => Carbon::now("UTC"),
                "last_six_tran_id" => $validatedArr["lastSixTranId"],
                "payment_accounts_id" => $validatedArr["PaymentAccountId"],
                "payment_method_id" => $validatedArr["PaymentMethodId"],
                "status" => "pending"
            ];

            $transaction = Transaction::create($arr);
            $transaction->save();

            return ResponseModel::ok("Deposit Requested Successfully! Please wait for admin approval!!", null);
        } catch (Exception $ex) {
            $this->logger->error($ex->getMessage());
            return ResponseModel::internalServer("Deposit request creation failed!", null);
        }

    }
}
