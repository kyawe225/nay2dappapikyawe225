<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\GameHistoryRequest;
use App\Http\Requests\Web\BetRequest;
use App\Http\ServiceContractor\IBetService;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Group;

#[Group("Customer Betting Handler", "APIs for managing users bettings")]
class BetController extends Controller
{
    public function __construct(private IBetService $service) {}

    public function Index(GameHistoryRequest $request, string $game): JsonResponse
    {
        $response = $this->service->Index($game, $request, auth()->user());

        return response()->json($response, $response->status);
    }

    public function Show(string $game, string $bet): JsonResponse
    {
        $response = $this->service->Show($game, $bet, auth()->user());

        return response()->json($response, $response->status);
    }

    public function Create(BetRequest $request, string $game): JsonResponse
    {
        $response = $this->service->Create($game, $request, auth()->user());

        return response()->json($response, $response->status);
    }

    public function Results(GameHistoryRequest $request, string $game): JsonResponse
    {
        $response = $this->service->Results($game, $request);

        return response()->json($response, $response->status);
    }

    public function Odds(GameHistoryRequest $request, string $game): JsonResponse
    {
        $response = $this->service->Odds($game, $request);

        return response()->json($response, $response->status);
    }
}
