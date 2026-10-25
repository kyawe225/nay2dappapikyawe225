<?php

namespace App\Http\ServiceContractor;

use App\Http\Requests\Admin\PaymentReviewRequest;
use App\Http\Requests\DepositRequest;
use App\Http\Requests\PaymentHistoryRequest;
use App\Http\Requests\WithdrawalRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WithdrawRequest;
use App\Response\ResponseModel;

interface IPaymentService
{
    public function DepositHistory(User $user): ResponseModel;

    public function WithdrawHistory(User $user): ResponseModel;

    public function WithdrawRequestHistory(User $user): ResponseModel;

    public function CreateNewDepositRequest(DepositRequest $request, User $user): ResponseModel;

    public function CreateNewWithdrawalRequest(WithdrawalRequest $request, User $user): ResponseModel;

    public function DepositRequests(PaymentHistoryRequest $request): ResponseModel;

    public function WithdrawalRequests(PaymentHistoryRequest $request): ResponseModel;

    public function ReviewDeposit(PaymentReviewRequest $request, Transaction $transaction, User $user): ResponseModel;

    public function ReviewWithdrawal(PaymentReviewRequest $request, WithdrawRequest $withdrawal, User $user): ResponseModel;

    public function Wallet(User $user): ResponseModel;

    public function WalletHistory(PaymentHistoryRequest $request, User $user): ResponseModel;
}
