<?php

namespace App\Http\ServiceContractor;

use App\Http\Requests\Admin\WalletHistoryRequest;
use App\Http\Requests\Admin\WalletManagementRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Response\ResponseModel;

interface IWalletService
{
    public function GetWallet(User $user): Wallet;

    public function ShowForCustomer(string $userId): ResponseModel;

    public function HistoryForCustomer(string $userId, WalletHistoryRequest $request): ResponseModel;

    public function TransactionForCustomer(string $userId, string $transactionId): ResponseModel;

    public function WithdrawalForCustomer(string $userId, string $withdrawalId): ResponseModel;

    public function MovementForCustomer(string $userId, string $movementId): ResponseModel;

    public function CreateForCustomer(string $userId, WalletManagementRequest $request, User $actor): ResponseModel;

    public function AdjustForCustomer(string $userId, WalletManagementRequest $request, User $actor): ResponseModel;

    public function DeleteForCustomer(string $userId, WalletManagementRequest $request, User $actor): ResponseModel;

    public function Move(Wallet $wallet, string $type, string $direction, string $amount, string $referenceType, string $referenceId): void;

    public function RecordMovement(Wallet $wallet, string $type, string $direction, string $amount, string $balanceBefore, string $referenceType, string $referenceId): void;
}
