<?php

namespace App\Http\ServiceContractor;

use App\Http\Requests\Admin\CancelDrawRequest;
use App\Http\Requests\Admin\DrawRequest;
use App\Http\Requests\Admin\OddRequest;
use App\Http\Requests\Admin\SettlementRequest;
use App\Http\Requests\GameHistoryRequest;
use App\Http\Requests\Web\BetRequest;
use App\Models\User;
use App\Response\ResponseModel;

interface IBetService
{
    public function Index(string $game, GameHistoryRequest $request, User $user): ResponseModel;

    public function Show(string $game, string $betId, User $user): ResponseModel;

    public function Create(string $game, BetRequest $request, User $user): ResponseModel;

    public function Results(string $game, GameHistoryRequest $request, bool $administrator = false): ResponseModel;

    public function Odds(string $game, GameHistoryRequest $request, bool $administrator = false): ResponseModel;

    public function SaveOdd(string $game, OddRequest $request, User $user, ?string $oddId = null): ResponseModel;

    public function SaveResult(string $game, DrawRequest $request, User $user): ResponseModel;

    public function Preview(string $game, SettlementRequest $request): ResponseModel;

    public function Settle(string $game, SettlementRequest $request, User $user): ResponseModel;

    public function Cancel(string $game, CancelDrawRequest $request, User $user): ResponseModel;

    public function Recalculate(string $game, SettlementRequest $request): ResponseModel;

    public function CalculateResults(string $game, string $drawDate, string $timeType, string $revision): void;

    public function CalculationFailed(string $game, string $drawDate, string $timeType, string $revision): void;
}
