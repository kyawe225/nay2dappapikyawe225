<?php

namespace App\Http\ServiceContractor;

use App\Http\Requests\Admin\AdminListRequest;
use App\Http\Requests\Admin\PaymentMethodRequest;
use App\Models\User;
use App\Response\ResponseModel;

interface IPaymentMethodService
{
    public function AdminIndex(AdminListRequest $request): ResponseModel;

    public function AdminShow(string $recordId): ResponseModel;

    public function AdminCreate(PaymentMethodRequest $request, User $actor): ResponseModel;

    public function AdminUpdate(PaymentMethodRequest $request, string $recordId, User $actor): ResponseModel;

    public function AdminDelete(string $recordId, User $actor): ResponseModel;

    public function DepositPaymentAccounts(): ResponseModel;

    public function WithdrawalPaymentAccounts(User $user): ResponseModel;
}
