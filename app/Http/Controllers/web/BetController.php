<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\GameHistoryRequest;
use App\Http\Requests\Web\BetRequest;
use App\Http\ServiceContractor\IBetService;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;

#[Group('Customer Betting Handler', 'APIs for managing users bettings')]
#[Authenticated]
#[Response(status: 401, content: ['message' => 'Unauthenticated.'], description: 'Missing or invalid bearer token.')]
class BetController extends Controller
{
    public function __construct(private IBetService $service) {}

    public function AvailableDraws(GameHistoryRequest $request, string $game): JsonResponse
    {
        $response = $this->service->AvailableDraws($game, $request);

        return response()->json($response, $response->status);
    }

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
