<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CancelDrawRequest;
use App\Http\Requests\Admin\DrawRequest;
use App\Http\Requests\Admin\OddRequest;
use App\Http\Requests\Admin\SettlementRequest;
use App\Http\Requests\GameHistoryRequest;
use App\Http\ServiceContractor\IBetService;
use Illuminate\Http\JsonResponse;

class BetController extends Controller
{
    public function __construct(private IBetService $service) {}

    public function Index(GameHistoryRequest $request, string $game): JsonResponse
    {
        $response = $this->service->Index($game, $request, auth()->user());

        return response()->json($response, $response->status);
    }

    public function Results(GameHistoryRequest $request, string $game): JsonResponse
    {
        $response = $this->service->Results($game, $request, true);

        return response()->json($response, $response->status);
    }

    public function Odds(GameHistoryRequest $request, string $game): JsonResponse
    {
        $response = $this->service->Odds($game, $request, true);

        return response()->json($response, $response->status);
    }

    public function CreateOdd(OddRequest $request, string $game): JsonResponse
    {
        $response = $this->service->SaveOdd($game, $request, auth()->user());

        return response()->json($response, $response->status);
    }

    public function UpdateOdd(OddRequest $request, string $game, string $odd): JsonResponse
    {
        $response = $this->service->SaveOdd($game, $request, auth()->user(), $odd);

        return response()->json($response, $response->status);
    }

    public function SaveResult(DrawRequest $request, string $game): JsonResponse
    {
        $response = $this->service->SaveResult($game, $request, auth()->user());

        return response()->json($response, $response->status);
    }

    public function Preview(SettlementRequest $request, string $game): JsonResponse
    {
        $response = $this->service->Preview($game, $request);

        return response()->json($response, $response->status);
    }

    public function Settle(SettlementRequest $request, string $game): JsonResponse
    {
        $response = $this->service->Settle($game, $request, auth()->user());

        return response()->json($response, $response->status);
    }

    public function Recalculate(SettlementRequest $request, string $game): JsonResponse
    {
        $response = $this->service->Recalculate($game, $request);

        return response()->json($response, $response->status);
    }

    public function Cancel(CancelDrawRequest $request, string $game): JsonResponse
    {
        $response = $this->service->Cancel($game, $request, auth()->user());

        return response()->json($response, $response->status);
    }
}
