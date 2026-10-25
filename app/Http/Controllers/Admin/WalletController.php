<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\WalletHistoryRequest;
use App\Http\Requests\Admin\WalletManagementRequest;
use App\Http\ServiceContractor\IWalletService;
use Illuminate\Http\JsonResponse;

class WalletController extends Controller
{
    public function __construct(private IWalletService $service) {}

    public function Show(string $account): JsonResponse
    {
        $response = $this->service->ShowForCustomer($account);

        return response()->json($response, $response->status);
    }

    public function Create(WalletManagementRequest $request, string $account): JsonResponse
    {
        $response = $this->service->CreateForCustomer($account, $request, $request->user());

        return response()->json($response, $response->status);
    }

    public function History(WalletHistoryRequest $request, string $account): JsonResponse
    {
        $response = $this->service->HistoryForCustomer($account, $request);

        return response()->json($response, $response->status);
    }

    public function Transaction(string $account, string $transaction): JsonResponse
    {
        $response = $this->service->TransactionForCustomer($account, $transaction);

        return response()->json($response, $response->status);
    }

    public function Withdrawal(string $account, string $withdrawal): JsonResponse
    {
        $response = $this->service->WithdrawalForCustomer($account, $withdrawal);

        return response()->json($response, $response->status);
    }

    public function Movement(string $account, string $movement): JsonResponse
    {
        $response = $this->service->MovementForCustomer($account, $movement);

        return response()->json($response, $response->status);
    }

    public function Adjust(WalletManagementRequest $request, string $account): JsonResponse
    {
        $response = $this->service->AdjustForCustomer($account, $request, $request->user());

        return response()->json($response, $response->status);
    }

    public function Delete(WalletManagementRequest $request, string $account): JsonResponse
    {
        $response = $this->service->DeleteForCustomer($account, $request, $request->user());

        return response()->json($response, $response->status);
    }
}
