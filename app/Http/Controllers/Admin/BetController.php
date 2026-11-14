<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BettingCutoffSettingRequest;
use App\Http\Requests\Admin\CalculationScheduleRequest;
use App\Http\Requests\Admin\CalculationSettingRequest;
use App\Http\Requests\Admin\CancelDrawRequest;
use App\Http\Requests\Admin\DrawCutoffRequest;
use App\Http\Requests\Admin\DrawRequest;
use App\Http\Requests\Admin\DrawScheduleRequest;
use App\Http\Requests\Admin\OddRequest;
use App\Http\Requests\Admin\SettlementRequest;
use App\Http\Requests\GameHistoryRequest;
use App\Http\ServiceContractor\IBetService;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;

#[Group('Admin Bet Handler', 'APIs for managing admin and users bets')]

#[Authenticated]
#[Response(status: 401, content: ['message' => 'Unauthenticated.'], description: 'Missing or invalid bearer token.')]
class BetController extends Controller
{
    public function __construct(private IBetService $service) {}

    /** Read the saved betting cutoff for each session. Existing draws retain their cutoff. */
    public function BettingCutoffSettings(string $game): JsonResponse
    {
        $response = $this->service->BettingCutoffSettings($game);

        return response()->json($response, $response->status);
    }

    /** Update the cutoff used by newly scheduled draws. */
    public function SaveBettingCutoffSetting(BettingCutoffSettingRequest $request, string $game): JsonResponse
    {
        $response = $this->service->SaveBettingCutoffSetting($game, $request, auth()->user());

        return response()->json($response, $response->status);
    }

    /** Read the defaults used when scheduling new draws. Times use the betting timezone. */
    public function CalculationSettings(string $game): JsonResponse
    {
        $response = $this->service->CalculationSettings($game);

        return response()->json($response, $response->status);
    }

    /**
     * Update the default for new draws. Existing draws retain their own schedule.
     *
     * @bodyParam calculationMode string required One of immediate, scheduled, manual. Example: scheduled
     * @bodyParam calculationTime string Required for scheduled mode; HH:mm on the draw date in the betting timezone. Example: 12:01
     */
    public function SaveCalculationSetting(CalculationSettingRequest $request, string $game): JsonResponse
    {
        $response = $this->service->SaveCalculationSetting($game, $request, auth()->user());

        return response()->json($response, $response->status);
    }

    /** Override a draw's calculation mode and time before calculation completes. */
    public function ScheduleCalculation(CalculationScheduleRequest $request, string $game): JsonResponse
    {
        $response = $this->service->ScheduleCalculation($game, $request, auth()->user());

        return response()->json($response, $response->status);
    }

    public function Schedule(DrawScheduleRequest $request, string $game): JsonResponse
    {
        $response = $this->service->Schedule($game, $request, auth()->user());

        return response()->json($response, $response->status);
    }

    /** Change the cutoff for a selected open draw without changing the master setting. */
    public function UpdateDrawCutoff(DrawCutoffRequest $request, string $game): JsonResponse
    {
        $response = $this->service->UpdateDrawCutoff($game, $request, auth()->user());

        return response()->json($response, $response->status);
    }

    public function Index(GameHistoryRequest $request, string $game): JsonResponse
    {
        $response = $this->service->Index($game, $request, auth()->user(), true);

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

    /** Queue calculation now, overriding the schedule or manual mode. Wallet payment requires separate settlement. */
    public function Recalculate(SettlementRequest $request, string $game): JsonResponse
    {
        $response = $this->service->Recalculate($game, $request, auth()->user());

        return response()->json($response, $response->status);
    }

    public function Cancel(CancelDrawRequest $request, string $game): JsonResponse
    {
        $response = $this->service->Cancel($game, $request, auth()->user());

        return response()->json($response, $response->status);
    }
}
