<?php

namespace App\Service;

use App\Http\Requests\Admin\CalculationScheduleRequest;
use App\Http\Requests\Admin\CalculationSettingRequest;
use App\Http\Requests\Admin\CancelDrawRequest;
use App\Http\Requests\Admin\DrawRequest;
use App\Http\Requests\Admin\DrawScheduleRequest;
use App\Http\Requests\Admin\OddRequest;
use App\Http\Requests\Admin\SettlementRequest;
use App\Http\Requests\GameHistoryRequest;
use App\Http\Requests\Web\BetRequest;
use App\Http\Resources\BetResource;
use App\Http\Resources\DrawResource;
use App\Http\Resources\OddResource;
use App\Http\ServiceContractor\IBetService;
use App\Http\ServiceContractor\IWalletService;
use App\Jobs\CalculateBetResults;
use App\Models\ThreedDraw;
use App\Models\ThreedEntry;
use App\Models\ThreedOdd;
use App\Models\TwodDraw;
use App\Models\TwodEntry;
use App\Models\TwodOdd;
use App\Models\User;
use App\Response\ResponseModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BetService implements IBetService
{
    public function __construct(private IWalletService $walletService, private AdminActivityService $activities) {}

    public function CalculationSettings(string $game): ResponseModel
    {
        $items = DB::table('result_calculation_settings')->where('game', $game)->orderBy('time_type')->get()
            ->map(fn (object $setting): array => [
                'calculationMode' => $setting->calculation_mode, 'calculationTime' => $setting->calculation_time,
                ...($game === 'twod' ? ['timeType' => $setting->time_type] : []),
            ]);

        return ResponseModel::ok('Calculation settings fetched successfully!', ['items' => $items, 'timezone' => config('betting.timezone')]);
    }

    public function SaveCalculationSetting(string $game, CalculationSettingRequest $request, User $user): ResponseModel
    {
        return DB::transaction(function () use ($game, $request, $user): ResponseModel {
            $data = $request->validated();
            DB::table('result_calculation_settings')->where('game', $game)->where('time_type', $data['timeType'] ?? 'draw')->update([
                'calculation_mode' => $data['calculationMode'], 'calculation_time' => $data['calculationTime'] ?? null, 'updated_at' => now('UTC'),
            ]);
            $this->activities->Record($user, 'calculation.setting.updated', 'result_calculation_settings', null, ['game' => $game, ...$data]);

            return $this->CalculationSettings($game);
        });
    }

    public function ScheduleCalculation(string $game, CalculationScheduleRequest $request, User $user): ResponseModel
    {
        return DB::transaction(function () use ($game, $request, $user): ResponseModel {
            $data = $request->validated();
            $this->LockSession($game, $data['drawDate'], $data['timeType'] ?? null);
            $draw = $this->FindDraw($game, $data['drawDate'], $data['timeType'] ?? null);
            if ($draw === null) {
                return ResponseModel::NotFound('Draw was not found!', null);
            }
            if ($draw->settled_at !== null || $draw->cancelled_at !== null || $draw->calculation_status === 'completed') {
                return ResponseModel::conflict('Calculation settings cannot change for a completed result!', null);
            }
            $draw->fill($this->CalculationAttributes($data));
            $draw->calculation_status = 'pending';
            if ($draw->winning_number !== null) {
                $draw->result_revision = (string) Str::uuid7();
            }
            $draw->save();
            $this->QueueCalculation($game, $draw);
            $this->activities->Record($user, 'calculation.scheduled', $game.'_draws', $draw->id, $data);

            return ResponseModel::ok('Calculation schedule updated successfully!', new DrawResource($draw->refresh()));
        }, 3);
    }

    /** @param array<string, mixed> $data
     * @return array{calculation_mode: string, calculation_time: ?string, calculation_scheduled_at: ?CarbonImmutable}
     */
    private function CalculationAttributes(array $data): array
    {
        $mode = $data['calculationMode'];
        $time = $mode === 'scheduled' ? $data['calculationTime'] : null;

        return [
            'calculation_mode' => $mode, 'calculation_time' => $time,
            'calculation_scheduled_at' => $time === null ? null : CarbonImmutable::parse($data['drawDate'].' '.$time, config('betting.timezone'))->utc(),
        ];
    }

    private function QueueCalculation(string $game, Model $draw): void
    {
        if ($draw->winning_number !== null && $draw->calculation_status !== 'completed' && $this->CalculationIsDue($draw)) {
            $draw->calculation_status = 'pending';
            $draw->save();
            CalculateBetResults::dispatch($game, $draw->draw_date->toDateString(), $game === 'twod' ? $draw->time_type : null, $draw->result_revision)->afterCommit();
        }
    }

    private function CalculationIsDue(Model $draw): bool
    {
        return $draw->calculation_mode === 'immediate'
            || ($draw->calculation_mode === 'scheduled' && $draw->calculation_scheduled_at !== null && $draw->calculation_scheduled_at->lessThanOrEqualTo(now('UTC')));
    }

    public function CalculateDueResults(): int
    {
        $count = 0;
        foreach (['twod', 'threed'] as $game) {
            $this->DrawQuery($game)->whereNotNull('winning_number')->whereNotNull('result_revision')
                ->whereNull('settled_at')->whereNull('cancelled_at')->where('calculation_status', 'pending')
                ->where(function (Builder $query): void {
                    $query->where('calculation_mode', 'immediate')->orWhere(function (Builder $query): void {
                        $query->where('calculation_mode', 'scheduled')->where('calculation_scheduled_at', '<=', now('UTC'));
                    });
                })->chunkById(100, function (Collection $draws) use ($game, &$count): void {
                    foreach ($draws as $draw) {
                        $this->CalculateResults($game, $draw->draw_date->toDateString(), $game === 'twod' ? $draw->time_type : null, $draw->result_revision);
                        $count++;
                    }
                });
        }

        return $count;
    }

    public function Schedule(string $game, DrawScheduleRequest $request, User $user): ResponseModel
    {
        return DB::transaction(function () use ($game, $request, $user): ResponseModel {
            $data = $request->validated();
            $date = CarbonImmutable::parse($data['drawDate'], config('betting.timezone'));
            if ($game === 'threed') {
                $this->LockSession($game, $date->startOfMonth()->toDateString(), null);
            }
            $this->LockSession($game, $data['drawDate'], $data['timeType'] ?? null);
            if ($this->DrawQuery($game)->withTrashed()->whereDate('draw_date', $data['drawDate'])
                ->when($game === 'twod', fn (Builder $query): Builder => $query->where('time_type', $data['timeType']))->exists()) {
                return ResponseModel::conflict('A draw already exists for this date!', null);
            }
            if ($game === 'threed' && $this->DrawQuery($game)->withTrashed()
                ->whereDate('draw_date', '>=', $date->startOfMonth()->toDateString())
                ->whereDate('draw_date', '<=', $date->endOfMonth()->toDateString())
                ->lockForUpdate()->get()->count() >= 2) {
                return ResponseModel::conflict('Only two 3D draw dates can be scheduled per month!', null);
            }
            $draw = $this->DrawQuery($game)->getModel()->newInstance([
                'draw_date' => $data['drawDate'], 'open_date' => $data['openDate'] ?? $data['drawDate'],
                'open_time' => $data['openTime'], 'cutoff_time' => $data['cutoffTime'],
                'name' => $data['name'] ?? null, 'winning_number' => null,
                ...($game === 'twod' ? ['time_type' => $data['timeType']] : []),
            ]);
            if (! isset($data['calculationMode'])) {
                $setting = DB::table('result_calculation_settings')->where('game', $game)->where('time_type', $data['timeType'] ?? 'draw')->first();
                $data['calculationMode'] = $setting->calculation_mode;
                $data['calculationTime'] = $setting->calculation_time;
            }
            $draw->fill($this->CalculationAttributes($data));
            if ($this->OpensAt($draw)->greaterThanOrEqualTo($this->ClosesAt($draw))) {
                return ResponseModel::conflict('The betting cutoff must be after the opening time!', null);
            }
            if ($this->ClosesAt($draw)->lessThanOrEqualTo(CarbonImmutable::now(config('betting.timezone')))) {
                return ResponseModel::conflict('The betting cutoff must be in the future!', null);
            }
            $draw->save();
            $this->activities->Record($user, 'draw.scheduled', $game.'_draws', $draw->id, $data);

            return ResponseModel::ok('Draw scheduled successfully!', new DrawResource($draw->refresh()));
        }, 3);
    }

    public function AvailableDraws(string $game, GameHistoryRequest $request): ResponseModel
    {
        $now = CarbonImmutable::now(config('betting.timezone'));
        $query = $this->DrawQuery($game)->whereNull('winning_number')->whereNull('cancelled_at')->whereNull('settled_at')
            ->whereNotNull('open_time')->whereNotNull('cutoff_time')
            ->where('draw_date', '>=', $now->toDateString())
            ->whereDate(DB::raw('COALESCE(open_date, draw_date)'), '<=', $now->toDateString());
        $this->Filter($query, $request, 'time_type');
        $draws = $query->orderBy('draw_date')->orderBy('open_time')->get()
            ->filter(fn (Model $draw): bool => $this->IsOpen($draw))->values();

        return ResponseModel::ok('Available draws fetched successfully!', ['items' => DrawResource::collection($draws)]);
    }

    public function Index(string $game, GameHistoryRequest $request, User $user): ResponseModel
    {
        $query = $this->BetQuery($game);
        if (! $user->isAdministrator()) {
            $query->where('user_id', $user->id);
        } elseif ($request->filled('paymentUserId')) {
            $query->where('user_id', $request->validated('paymentUserId'));
        }
        $this->Filter($query, $request, 'time');
        foreach (['status' => 'status', 'resultStatus' => 'result_status'] as $field => $column) {
            if ($request->filled($field)) {
                $query->where($column, $request->validated($field));
            }
        }

        return ResponseModel::ok('Bets fetched successfully!', $this->Paginate($query, $request, BetResource::class));
    }

    public function Show(string $game, string $betId, User $user): ResponseModel
    {
        $bet = $this->BetQuery($game)->where('user_id', $user->id)->find($betId);

        return $bet === null ? ResponseModel::NotFound('Bet was not found!', null) : ResponseModel::ok('Bet fetched successfully!', new BetResource($bet));
    }

    public function Create(string $game, BetRequest $request, User $user): ResponseModel
    {
        return DB::transaction(function () use ($game, $request, $user): ResponseModel {
            $data = $request->validated();
            $data['timeType'] ??= null;
            $this->LockSession($game, $data['drawDate'], $data['timeType']);
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $entries = array_map(fn (array $entry): array => ['number' => $entry['number'], 'stakeAmount' => bcadd((string) $entry['stakeAmount'], '0', 2)], $data['entries']);
            $requestHash = hash('sha256', json_encode([$data['drawDate'], $data['timeType'], $entries], JSON_THROW_ON_ERROR));
            $existing = $this->BetQuery($game)->where('user_id', $user->id)->where('client_reference', $data['clientReference'])->orderBy('entry_index')->get();

            if ($existing->isNotEmpty()) {
                $existingHash = $game === 'threed'
                    ? hash('sha256', json_encode([$existing->first()->draw_date->toDateString(), null, $existing->map(fn (Model $bet): array => [
                        'number' => $bet->draw_number, 'stakeAmount' => bcadd($bet->stake_amount, '0', 2),
                    ])->all()], JSON_THROW_ON_ERROR))
                    : $existing->first()->request_hash;

                return $existingHash === $requestHash
                    ? ResponseModel::ok('Bet request already exists!', $this->BatchData($existing))
                    : ResponseModel::conflict('Client reference is already used for a different bet!', null);
            }
            if ($user->status !== 'active' || $user->isAdministrator()) {
                return new ResponseModel(403, 'This account cannot place bets!', null);
            }
            $draw = $this->FindDraw($game, $data['drawDate'], $data['timeType']);
            if ($draw === null || ! $this->IsOpen($draw)) {
                return ResponseModel::conflict('Betting is only available for an existing open draw!', null);
            }
            $odd = $this->OddQuery($game)->latest('created_at')->latest('id')->lockForUpdate()->first();
            if ($odd === null || ! in_array($odd->payout_mode, ['total', 'profit'], true)) {
                return ResponseModel::conflict('Betting odds are not configured for this game type!', null);
            }
            $totalStake = '0.00';
            foreach ($entries as $index => $entry) {
                $payout = bcmul($entry['stakeAmount'], $odd->odd_number, 6);
                if ($odd->payout_mode === 'profit') {
                    $payout = bcadd($payout, $entry['stakeAmount'], 6);
                }
                $entries[$index]['potentialPayout'] = bcadd(bcadd($payout, '0.005', 6), '0', 2);
                $totalStake = bcadd($totalStake, $entry['stakeAmount'], 2);
                if (bccomp($entries[$index]['potentialPayout'], str_repeat('9', 50).'.99', 2) > 0) {
                    return ResponseModel::badRequest('The potential payout exceeds the supported amount!', null);
                }
            }
            $wallet = $this->walletService->GetWallet($user);
            if (bccomp($wallet->avaliable_balance, $totalStake, 2) < 0) {
                return ResponseModel::badRequest('Insufficient available balance!', null);
            }
            $batchId = (string) Str::uuid7();
            $bets = new Collection;
            foreach ($entries as $index => $entry) {
                $bets->push($this->BetQuery($game)->create([
                    'draw_date' => $data['drawDate'],
                    ...($game === 'twod' ? ['time' => $data['timeType']] : []),
                    'draw_number' => $entry['number'], 'user_id' => $user->id,
                    'stake_amount' => $entry['stakeAmount'], 'potential_payout' => $entry['potentialPayout'],
                    'settle_amount' => '0.00', 'status' => 'active', 'result_status' => 'pending',
                    'odd_id' => $odd->id, 'accepted_odds' => $odd->odd_number,
                    'batch_id' => $batchId, 'client_reference' => $data['clientReference'],
                    'entry_index' => $index, 'request_hash' => $requestHash,
                ]));
            }
            $this->walletService->Move($wallet, 'bet_placed', 'out', $totalStake, $game.'_bet_batch', $batchId);

            return ResponseModel::ok('Bets placed successfully!', $this->BatchData($bets));
        }, 3);
    }

    /** @return array{batchId: string, clientReference: string, totalStake: string, entries: mixed} */
    private function BatchData(Collection $bets): array
    {
        $totalStake = '0.00';
        foreach ($bets as $bet) {
            $totalStake = bcadd($totalStake, $bet->stake_amount, 2);
        }

        return [
            'batchId' => $bets->first()->batch_id,
            'clientReference' => $bets->first()->client_reference,
            'totalStake' => $totalStake,
            'entries' => BetResource::collection($bets),
        ];
    }

    public function Results(string $game, GameHistoryRequest $request, bool $administrator = false): ResponseModel
    {
        $query = $this->DrawQuery($game);
        if (! $administrator) {
            $query->where(fn (Builder $query): Builder => $query->whereNotNull('winning_number')->orWhereNotNull('cancelled_at'));
        }
        $this->Filter($query, $request, 'time_type');

        return ResponseModel::ok('Results fetched successfully!', $this->Paginate($query, $request, DrawResource::class));
    }

    public function Odds(string $game, GameHistoryRequest $request, bool $administrator = false): ResponseModel
    {
        $query = $this->OddQuery($game);
        if ($administrator) {
            return ResponseModel::ok('Odds settings fetched successfully!', $this->Paginate($query, $request, OddResource::class));
        }
        $odd = $query->latest('created_at')->latest('id')->first();

        return $odd === null ? ResponseModel::NotFound('Odds settings were not found!', null) : ResponseModel::ok('Odds fetched successfully!', new OddResource($odd));
    }

    public function SaveOdd(string $game, OddRequest $request, User $user, ?string $oddId = null): ResponseModel
    {
        return DB::transaction(function () use ($game, $request, $user, $oddId): ResponseModel {
            $data = $request->validated();
            $odd = $oddId === null ? $this->OddQuery($game)->getModel()->newInstance() : $this->OddQuery($game)->lockForUpdate()->find($oddId);
            if ($odd === null) {
                return ResponseModel::NotFound('Odds setting was not found!', null);
            }
            if ($oddId !== null) {
                return ResponseModel::conflict('Odds settings cannot be edited. Add a new odds row instead!', null);
            }
            $odd->fill(['name' => $data['name'], 'odd_number' => $data['oddNumber'], 'start_date' => $data['startDate'] ?? now()->toDateString(), 'payout_mode' => $data['payoutMode']])->save();
            $this->activities->Record($user, 'odds.saved', $game.'_odds', $odd->id, $data);

            return ResponseModel::ok('Odds setting saved successfully!', new OddResource($odd));
        }, 3);
    }

    public function SaveResult(string $game, DrawRequest $request, User $user): ResponseModel
    {
        return DB::transaction(function () use ($game, $request, $user): ResponseModel {
            $data = $request->validated();
            $data['timeType'] ??= null;
            $this->LockSession($game, $data['drawDate'], $data['timeType']);
            $draw = $this->FindDraw($game, $data['drawDate'], $data['timeType']);
            if ($draw !== null && ($draw->settled_at !== null || $draw->cancelled_at !== null || ($draw->winning_number !== null && $draw->winning_number !== $data['winningNumber']))) {
                return ResponseModel::conflict('This result is already recorded or completed!', null);
            }
            if ($draw === null) {
                return ResponseModel::NotFound('Draw was not found! Schedule the draw first.', null);
            }
            if (isset($data['calculationMode'])) {
                if ($draw->calculation_status === 'completed') {
                    return ResponseModel::conflict('Calculation settings cannot change for a completed result!', null);
                }
                $draw->fill($this->CalculationAttributes($data));
                $draw->calculation_status = 'pending';
                $draw->result_revision = null;
            }
            $draw->fill(['winning_number' => $data['winningNumber'], 'name' => $data['name'] ?? $draw->name]);
            if ($draw->result_revision === null) {
                $draw->result_revision = (string) Str::uuid7();
                $draw->recorded_by = $user->id;
            }
            $draw->save();
            $this->QueueCalculation($game, $draw);
            $this->activities->Record($user, 'result.saved', $game.'_draws', $draw->id, $data);

            return ResponseModel::ok('Result saved successfully!', new DrawResource($draw->refresh()));
        }, 3);
    }

    public function CalculateResults(string $game, string $drawDate, ?string $timeType, string $revision, bool $force = false): void
    {
        DB::transaction(function () use ($game, $drawDate, $timeType, $revision, $force): void {
            $this->LockSession($game, $drawDate, $timeType);
            $draw = $this->FindDraw($game, $drawDate, $timeType);
            if ($draw === null || $draw->result_revision !== $revision || $draw->winning_number === null
                || $draw->settled_at !== null || $draw->cancelled_at !== null || $draw->calculation_status === 'completed'
                || (! $force && ! $this->CalculationIsDue($draw))) {
                return;
            }
            $this->SessionBets($game, $drawDate, $timeType)->where('status', 'active')->chunkById(500, function (Collection $bets) use ($draw): void {
                foreach ($bets as $bet) {
                    $won = $bet->draw_number === $draw->winning_number;
                    $bet->result_status = $won ? 'won' : 'lost';
                    $bet->settle_amount = $won ? $bet->potential_payout : '0.00';
                    $bet->calculated_at = now('UTC');
                    $bet->save();
                }
            });
            $draw->calculation_status = 'completed';
            $draw->calculated_at = now('UTC');
            $draw->save();
        }, 3);
    }

    public function CalculationFailed(string $game, string $drawDate, ?string $timeType, string $revision): void
    {
        DB::transaction(function () use ($game, $drawDate, $timeType, $revision): void {
            $this->LockSession($game, $drawDate, $timeType);
            $this->DrawQuery($game)->whereDate('draw_date', $drawDate)->when($game === 'twod', fn (Builder $query): Builder => $query->where('time_type', $timeType))
                ->where('result_revision', $revision)->whereNull('settled_at')->whereNull('cancelled_at')->where('calculation_status', 'pending')->update(['calculation_status' => 'failed']);
        });
    }

    public function Recalculate(string $game, SettlementRequest $request, User $user): ResponseModel
    {
        return DB::transaction(function () use ($game, $request, $user): ResponseModel {
            $data = $request->validated();
            $data['timeType'] ??= null;
            $this->LockSession($game, $data['drawDate'], $data['timeType']);
            $draw = $this->FindDraw($game, $data['drawDate'], $data['timeType']);
            if ($draw === null || $draw->winning_number === null) {
                return ResponseModel::NotFound('Result was not found!', null);
            }
            if ($draw->cancelled_at !== null || $draw->settled_at !== null) {
                return ResponseModel::conflict('This result is already completed!', null);
            }
            if ($draw->calculation_status !== 'completed') {
                $draw->calculation_status = 'pending';
                $draw->save();
                CalculateBetResults::dispatch($game, $data['drawDate'], $data['timeType'], $draw->result_revision, true)->afterCommit();
                $this->activities->Record($user, 'calculation.requested', $game.'_draws', $draw->id, $data);
            }

            return ResponseModel::ok('Result calculation requested!', new DrawResource($draw));
        }, 3);
    }

    public function Preview(string $game, SettlementRequest $request): ResponseModel
    {
        return DB::transaction(function () use ($game, $request): ResponseModel {
            $data = $request->validated();
            $data['timeType'] ??= null;
            $this->LockSession($game, $data['drawDate'], $data['timeType']);
            $draw = $this->FindDraw($game, $data['drawDate'], $data['timeType']);
            if ($draw === null) {
                return ResponseModel::NotFound('Result was not found!', null);
            }
            if ($draw->calculation_status !== 'completed' || $draw->cancelled_at !== null) {
                return ResponseModel::conflict('Result calculation must finish before reviewing payouts!', null);
            }
            $bets = $this->SessionBets($game, $data['drawDate'], $data['timeType'])->where('status', '!=', 'cancelled')->orderBy('id')->get();

            return ResponseModel::ok('Payout preview fetched successfully! No balances were changed.', $this->Snapshot($draw, $bets));
        }, 3);
    }

    public function Settle(string $game, SettlementRequest $request, User $user): ResponseModel
    {
        return DB::transaction(function () use ($game, $request, $user): ResponseModel {
            $data = $request->validated();
            $data['timeType'] ??= null;
            $this->LockSession($game, $data['drawDate'], $data['timeType']);
            $draw = $this->FindDraw($game, $data['drawDate'], $data['timeType']);
            if ($draw === null) {
                return ResponseModel::NotFound('Result was not found!', null);
            }
            if ($draw->settled_at !== null) {
                return ResponseModel::ok('This result is already paid!', new DrawResource($draw));
            }
            if ($draw->calculation_status !== 'completed' || $draw->cancelled_at !== null) {
                return ResponseModel::conflict('Result calculation must finish before payment!', null);
            }
            $bets = $this->SessionBets($game, $data['drawDate'], $data['timeType'])->where('status', 'active')->orderBy('id')->get();
            $snapshot = $this->Snapshot($draw, $bets);
            if (! hash_equals($snapshot['previewToken'], $data['previewToken'])) {
                return ResponseModel::conflict('The payout preview has changed. Review it again before payment!', null);
            }
            foreach ($bets->sortBy(fn (Model $bet): string => $bet->user_id.':'.$bet->id)->groupBy('user_id') as $userId => $customerBets) {
                $customer = User::query()->lockForUpdate()->findOrFail($userId);
                $wallet = $this->walletService->GetWallet($customer);
                foreach ($customerBets as $bet) {
                    if ($bet->result_status === 'pending') {
                        throw new \RuntimeException('A bet has not been calculated.');
                    }
                    if ($bet->result_status === 'won') {
                        $this->walletService->Move($wallet, 'bet_won', 'in', $bet->settle_amount, $game.'_bet', $bet->id);
                    }
                    $bet->status = $bet->result_status === 'won' ? 'settled' : 'lost';
                    $bet->settled_at = now('UTC');
                    $bet->save();
                }
            }
            $draw->settled_at = now('UTC');
            $draw->settled_by = $user->id;
            $draw->save();
            $this->activities->Record($user, 'result.paid', $game.'_draws', $draw->id, ['totalPayout' => $snapshot['totalPayout'], 'betCount' => $bets->count()]);

            return ResponseModel::ok('Winning bets paid successfully!', new DrawResource($draw));
        }, 3);
    }

    public function Cancel(string $game, CancelDrawRequest $request, User $user): ResponseModel
    {
        return DB::transaction(function () use ($game, $request, $user): ResponseModel {
            $data = $request->validated();
            $data['timeType'] ??= null;
            $this->LockSession($game, $data['drawDate'], $data['timeType']);
            $draw = $this->FindDraw($game, $data['drawDate'], $data['timeType']);
            if ($draw?->settled_at !== null) {
                return ResponseModel::conflict('A paid result cannot be cancelled!', null);
            }
            if ($draw?->cancelled_at !== null) {
                return ResponseModel::ok('This date and session are already cancelled!', new DrawResource($draw));
            }
            if ($draw === null) {
                return ResponseModel::NotFound('Draw was not found!', null);
            }
            $bets = $this->SessionBets($game, $data['drawDate'], $data['timeType'])->where('status', 'active')->orderBy('user_id')->orderBy('id')->get();
            foreach ($bets->groupBy('user_id') as $userId => $customerBets) {
                $customer = User::query()->lockForUpdate()->findOrFail($userId);
                $wallet = $this->walletService->GetWallet($customer);
                foreach ($customerBets as $bet) {
                    $this->walletService->Move($wallet, 'bet_refunded', 'in', $bet->stake_amount, $game.'_bet', $bet->id);
                    $bet->status = 'cancelled';
                    $bet->settle_amount = '0.00';
                    $bet->settled_at = now('UTC');
                    $bet->save();
                }
            }
            $draw->cancelled_at = now('UTC');
            $draw->cancelled_by = $user->id;
            $draw->cancellation_reason = $data['reason'];
            $draw->save();
            $this->activities->Record($user, 'result.cancelled', $game.'_draws', $draw->id, ['reason' => $data['reason']]);

            return ResponseModel::ok('Date and session cancelled! Accepted stakes were refunded.', new DrawResource($draw));
        }, 3);
    }

    /** @return array<string, mixed> */
    private function Snapshot(Model $draw, Collection $bets): array
    {
        $stake = '0.00';
        $payout = '0.00';
        foreach ($bets as $bet) {
            $stake = bcadd($stake, $bet->stake_amount, 2);
            $payout = bcadd($payout, $bet->settle_amount, 2);
        }
        $tokenData = [$draw->result_revision, $draw->winning_number, $bets->map(fn (Model $bet): array => [$bet->id, $bet->status, $bet->result_status, $bet->settle_amount])->all()];

        return [
            'result' => new DrawResource($draw), 'betCount' => $bets->count(),
            'winnerCount' => $bets->where('result_status', 'won')->count(),
            'loserCount' => $bets->where('result_status', 'lost')->count(),
            'totalStake' => $stake, 'totalPayout' => $payout,
            'previewToken' => hash('sha256', json_encode($tokenData, JSON_THROW_ON_ERROR)),
            'bets' => BetResource::collection($bets),
        ];
    }

    private function LockSession(string $game, string $drawDate, ?string $timeType): void
    {
        $this->BetQuery($game);
        $timeType = $game === 'threed' ? 'draw' : $timeType;
        DB::table('betting_sessions')->insertOrIgnore([
            'id' => (string) Str::uuid7(), 'game' => $game, 'draw_date' => $drawDate,
            'time_type' => $timeType, 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
        DB::table('betting_sessions')->where('game', $game)->where('draw_date', $drawDate)->where('time_type', $timeType)->lockForUpdate()->first();
    }

    private function FindDraw(string $game, string $drawDate, ?string $timeType): ?Model
    {
        return $this->DrawQuery($game)->whereDate('draw_date', $drawDate)
            ->when($game === 'twod', fn (Builder $query): Builder => $query->where('time_type', $timeType))->lockForUpdate()->first();
    }

    private function SessionBets(string $game, string $drawDate, ?string $timeType): Builder
    {
        return $this->BetQuery($game)->whereDate('draw_date', $drawDate)
            ->when($game === 'twod', fn (Builder $query): Builder => $query->where('time', $timeType));
    }

    private function OpensAt(Model $draw): CarbonImmutable
    {
        return CarbonImmutable::parse(($draw->open_date ?? $draw->draw_date)->toDateString().' '.$draw->open_time, config('betting.timezone'));
    }

    private function ClosesAt(Model $draw): CarbonImmutable
    {
        return CarbonImmutable::parse($draw->draw_date->toDateString().' '.$draw->cutoff_time, config('betting.timezone'));
    }

    private function IsOpen(Model $draw): bool
    {
        if ($draw->winning_number !== null || $draw->cancelled_at !== null || $draw->settled_at !== null
            || $draw->open_time === null || $draw->cutoff_time === null) {
            return false;
        }
        $now = CarbonImmutable::now(config('betting.timezone'));

        return $now->greaterThanOrEqualTo($this->OpensAt($draw)) && $now->lessThan($this->ClosesAt($draw));
    }

    private function BetQuery(string $game): Builder
    {
        return match ($game) {
            'twod' => TwodEntry::query(), 'threed' => ThreedEntry::query(),
            default => abort(404),
        };
    }

    private function DrawQuery(string $game): Builder
    {
        return match ($game) {
            'twod' => TwodDraw::query(), 'threed' => ThreedDraw::query(),
            default => abort(404),
        };
    }

    private function OddQuery(string $game): Builder
    {
        return match ($game) {
            'twod' => TwodOdd::query(), 'threed' => ThreedOdd::query(),
            default => abort(404),
        };
    }

    private function Filter(Builder $query, GameHistoryRequest $request, string $timeColumn): void
    {
        if ($request->filled('drawDate')) {
            $query->whereDate('draw_date', $request->validated('drawDate'));
        }
        if ($request->filled('timeType')) {
            $query->where($timeColumn, $request->validated('timeType'));
        }
    }

    /** @param class-string<JsonResource> $resource */
    private function Paginate(Builder $query, GameHistoryRequest $request, string $resource): array
    {
        $records = $query->latest('created_at')->latest('id')->paginate($request->integer('perPage', 20));

        return ['items' => $resource::collection($records), 'pagination' => ['currentPage' => $records->currentPage(), 'lastPage' => $records->lastPage(), 'perPage' => $records->perPage(), 'total' => $records->total()]];
    }
}
