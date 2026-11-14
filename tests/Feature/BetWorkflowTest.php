<?php

use App\Http\ServiceContractor\IBetService;
use App\Jobs\CalculateBetResults;
use App\Models\ThreedDraw;
use App\Models\ThreedEntry;
use App\Models\ThreedOdd;
use App\Models\TwodDraw;
use App\Models\TwodEntry;
use App\Models\TwodOdd;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** @return array<string, mixed> */
function bettingScenario(string $game): array
{
    test()->travelTo(CarbonImmutable::parse('2026-10-21 09:00', config('betting.timezone')));
    test()->seed(PaymentAccountSeeder::class);
    $customer = User::query()->where('email', 'customer1@example.com')->firstOrFail();
    $administrator = User::query()->where('email', 'test@example.com')->firstOrFail();
    $oddClass = $game === 'twod' ? TwodOdd::class : ThreedOdd::class;
    $odd = $oddClass::factory()->create();
    $customer->wallets()->create(['avaliable_balance' => '10000.00', 'locked_balance' => '0.00', 'version' => (string) Str::uuid7()]);
    $drawClass = $game === 'twod' ? TwodDraw::class : ThreedDraw::class;
    foreach (['2026-10-21', '2026-10-22'] as $date) {
        foreach ($game === 'twod' ? ['am', 'pm'] : [null] as $timeType) {
            $drawClass::factory()->create([
                'draw_date' => $date, 'open_date' => '2026-10-20', 'open_time' => '00:00',
                'cutoff_time' => '23:59', 'winning_number' => null,
                ...($game === 'twod' ? ['time_type' => $timeType] : []),
            ]);
        }
    }

    return [
        'customer' => $customer, 'administrator' => $administrator, 'odd' => $odd,
        'drawClass' => $game === 'twod' ? TwodDraw::class : ThreedDraw::class,
        'betClass' => $game === 'twod' ? TwodEntry::class : ThreedEntry::class,
        'number' => $game === 'twod' ? '07' : '007',
        'losingNumber' => $game === 'twod' ? '08' : '008',
    ];
}

/** @return array<string, mixed> */
function bettingPayload(string $number): array
{
    return ['drawDate' => '2026-10-21', ...(strlen($number) === 2 ? ['timeType' => 'am'] : []), 'clientReference' => (string) Str::uuid7(), 'entries' => [['number' => $number, 'stakeAmount' => '100.00']]];
}

test('bets on existing open draws and identical retries only debit the wallet once', function (string $game): void {
    $scenario = bettingScenario($game);
    Sanctum::actingAs($scenario['customer'], ['access']);
    $payload = bettingPayload($scenario['number']);

    $batchId = $this->postJson('/api/betting_bets_create/'.$game, $payload)->assertOk()
        ->assertJsonPath('data.entries.0.number', $scenario['number'])
        ->assertJsonMissingPath('data.entries.0.created_at')
        ->json('data.batchId');
    $this->getJson('/api/betting_bets_list/'.$game)->assertOk()
        ->assertJsonPath('data.items.0.placed_at', $scenario['betClass']::query()->sole()->created_at->toISOString());
    if ($game === 'threed') {
        $scenario['betClass']::query()->update(['request_hash' => hash('sha256', 'legacy AM/PM request hash')]);
    }
    $this->postJson('/api/betting_bets_create/'.$game, $payload)->assertOk()->assertJsonPath('data.batchId', $batchId);
    $this->postJson('/api/betting_bets_create/'.$game, [...$payload, 'drawDate' => '2026-10-22'])->assertConflict();

    $this->assertDatabaseCount($game.'_draws', $game === 'twod' ? 4 : 2);
    $this->assertDatabaseCount($game.'_bets', 1);
    $this->assertDatabaseHas('wallet', ['user_id' => $scenario['customer']->id, 'avaliable_balance' => 9900]);
    $this->assertDatabaseCount('wallet_transactions', 1);
})->with(['2D' => 'twod', '3D' => 'threed']);

test('a queued result calculates date and session matches without paying until an admin reviews and confirms', function (string $game, string $payout, string $balance): void {
    $scenario = bettingScenario($game);
    Sanctum::actingAs($scenario['customer'], ['access']);
    $payload = bettingPayload($scenario['number']);
    $payload['entries'][] = ['number' => $scenario['losingNumber'], 'stakeAmount' => '150.00'];
    $this->postJson('/api/betting_bets_create/'.$game, $payload)->assertOk();
    $this->postJson('/api/betting_bets_create/'.$game, [...bettingPayload($scenario['number']), ...($game === 'twod' ? ['timeType' => 'pm'] : ['drawDate' => '2026-10-22'])])->assertOk();
    Queue::fake([CalculateBetResults::class]);
    Sanctum::actingAs($scenario['administrator'], ['access']);
    $key = ['drawDate' => '2026-10-21', ...($game === 'twod' ? ['timeType' => 'am'] : [])];

    $this->postJson('/api/admin/betting_results_create/'.$game, [...$key, 'winningNumber' => $scenario['number']])->assertOk();
    Queue::assertPushed(CalculateBetResults::class, fn (CalculateBetResults $job): bool => $job->game === $game && $job->drawDate === '2026-10-21' && $job->timeType === ($game === 'twod' ? 'am' : null));
    $this->postJson('/api/admin/betting_results_preview/'.$game, $key)->assertConflict();
    $draw = $scenario['drawClass']::query()->whereNotNull('winning_number')->sole();
    (new CalculateBetResults($game, '2026-10-21', $game === 'twod' ? 'am' : null, $draw->result_revision))->handle(app(IBetService::class));
    $previewToken = $this->postJson('/api/admin/betting_results_preview/'.$game, $key)->assertOk()
        ->assertJsonPath('data.winnerCount', 1)->assertJsonPath('data.loserCount', 1)
        ->assertJsonPath('data.bets.0.user.id', $scenario['customer']->id)
        ->assertJsonMissingPath('data.bets.0.userId')
        ->assertJsonPath('data.totalPayout', $payout)->json('data.previewToken');
    $this->assertDatabaseHas('wallet', ['user_id' => $scenario['customer']->id, 'avaliable_balance' => 9650]);
    $this->assertDatabaseMissing('wallet_transactions', ['type' => 'bet_won']);
    $this->postJson('/api/admin/betting_results_settle/'.$game, $key)->assertUnprocessable()->assertJsonValidationErrors('previewToken');
    $this->postJson('/api/admin/betting_results_settle/'.$game, [...$key, 'previewToken' => str_repeat('0', 64)])->assertConflict();
    $this->postJson('/api/admin/betting_results_settle/'.$game, [...$key, 'previewToken' => $previewToken])->assertOk();
    $this->postJson('/api/admin/betting_results_settle/'.$game, [...$key, 'previewToken' => $previewToken])->assertOk();
    (new CalculateBetResults($game, '2026-10-21', $game === 'twod' ? 'am' : null, $draw->result_revision))->handle(app(IBetService::class));

    $this->assertDatabaseHas('wallet', ['user_id' => $scenario['customer']->id, 'avaliable_balance' => $balance]);
    $this->assertDatabaseHas('wallet_transactions', ['type' => 'bet_won', 'amount' => $payout]);
    $this->assertDatabaseCount('wallet_transactions', 3);
    expect($scenario['betClass']::query()->whereDate('draw_date', $game === 'twod' ? '2026-10-21' : '2026-10-22')
        ->where('result_status', 'pending')->where('status', 'active')->count())->toBe(1);
    $this->postJson('/api/admin/betting_results_create/'.$game, [...$key, 'winningNumber' => $scenario['losingNumber']])->assertConflict();
    Sanctum::actingAs($scenario['customer'], ['access']);
    $this->postJson('/api/betting_bets_create/'.$game, bettingPayload($scenario['number']))->assertConflict();
})->with(['2D' => ['twod', '8500.00', '18150.00'], '3D' => ['threed', '55000.00', '64650.00']]);

test('the newest odds row applies to every date and session while accepted bets retain their payout', function (string $game, string $initialOdds, string $initialPayout): void {
    $this->travelTo(now()->setDate(2026, 10, 21)->setTime(9, 0));
    $scenario = bettingScenario($game);
    $scenario['odd']->update(['start_date' => '2026-10-21']);
    Sanctum::actingAs($scenario['customer'], ['access']);
    $originalPayload = bettingPayload($scenario['number']);
    $originalBatch = $this->postJson('/api/betting_bets_create/'.$game, $originalPayload)->assertOk()
        ->assertJsonPath('data.entries.0.acceptedOdds', $initialOdds)
        ->assertJsonPath('data.entries.0.potentialPayout', $initialPayout)->json('data.batchId');
    $this->postJson('/api/betting_bets_create/'.$game, [...bettingPayload($scenario['number']), 'drawDate' => '2026-10-22', ...($game === 'twod' ? ['timeType' => 'pm'] : [])])
        ->assertOk()->assertJsonPath('data.entries.0.acceptedOdds', $initialOdds);
    Sanctum::actingAs($scenario['administrator'], ['access']);

    $this->patchJson('/api/admin/betting_odds_update/'.$game.'/'.$scenario['odd']->id, ['name' => 'Changed', 'oddNumber' => '90', 'payoutMode' => 'total'])->assertConflict();
    $this->postJson('/api/admin/betting_odds_create/'.$game, ['name' => 'Next rate', 'oddNumber' => '90', 'payoutMode' => 'total'])->assertOk();
    $latestId = $this->postJson('/api/admin/betting_odds_create/'.$game, ['name' => 'Latest rate', 'oddNumber' => '95', 'payoutMode' => 'profit'])
        ->assertOk()->assertJsonPath('data.startDate', '2026-10-21')->json('data.id');
    $this->getJson('/api/admin/betting_odds_list/'.$game)->assertOk()->assertJsonPath('data.pagination.total', 3)->assertJsonPath('data.items.0.id', $latestId);
    Sanctum::actingAs($scenario['customer'], ['access']);
    foreach (['2026-10-20', '2026-10-21', '2026-10-22'] as $drawDate) {
        $this->getJson('/api/betting_odds_list/'.$game.'?drawDate='.$drawDate)->assertOk()->assertJsonPath('data.id', $latestId);
    }
    foreach ($game === 'twod' ? [['timeType' => 'am'], ['timeType' => 'pm']] : [['drawDate' => '2026-10-21'], ['drawDate' => '2026-10-22']] as $key) {
        $this->postJson('/api/betting_bets_create/'.$game, [...bettingPayload($scenario['number']), ...$key])
            ->assertOk()->assertJsonPath('data.entries.0.acceptedOdds', '95.0000')->assertJsonPath('data.entries.0.potentialPayout', '9600.00');
    }
    $this->postJson('/api/betting_bets_create/'.$game, $originalPayload)->assertOk()->assertJsonPath('data.batchId', $originalBatch)
        ->assertJsonPath('data.entries.0.acceptedOdds', $initialOdds)->assertJsonPath('data.entries.0.potentialPayout', $initialPayout);

    $this->assertDatabaseHas($game.'_bets', ['batch_id' => $originalBatch, 'potential_payout' => $initialPayout, 'accepted_odds' => $initialOdds]);
    $this->assertDatabaseCount($game.'_draws', $game === 'twod' ? 4 : 2);
})->with(['2D' => ['twod', '85.0000', '8500.00'], '3D' => ['threed', '550.0000', '55000.00']]);

test('an odds start date is optional metadata and does not schedule a new setting', function (string $game): void {
    $scenario = bettingScenario($game);
    $otherGame = $game === 'twod' ? 'threed' : 'twod';
    $otherOddClass = $otherGame === 'twod' ? TwodOdd::class : ThreedOdd::class;
    $otherOdd = $otherOddClass::factory()->create();
    Sanctum::actingAs($scenario['administrator'], ['access']);
    $latestId = $this->postJson('/api/admin/betting_odds_create/'.$game, ['name' => 'New setting', 'oddNumber' => '90', 'startDate' => '2099-01-01', 'payoutMode' => 'total'])
        ->assertOk()->json('data.id');
    Sanctum::actingAs($scenario['customer'], ['access']);

    $this->getJson('/api/betting_odds_list/'.$game)->assertOk()->assertJsonPath('data.id', $latestId);
    $this->getJson('/api/betting_odds_list/'.$otherGame)->assertOk()->assertJsonPath('data.id', $otherOdd->id);
    $this->postJson('/api/betting_bets_create/'.$game, bettingPayload($scenario['number']))->assertOk()
        ->assertJsonPath('data.entries.0.acceptedOdds', '90.0000')->assertJsonPath('data.entries.0.potentialPayout', '9000.00');
})->with(['2D' => 'twod', '3D' => 'threed']);

test('odds changes require a new row even when no bets have used the current setting', function (string $game): void {
    $scenario = bettingScenario($game);
    Sanctum::actingAs($scenario['administrator'], ['access']);

    $this->patchJson('/api/admin/betting_odds_update/'.$game.'/'.$scenario['odd']->id, ['name' => 'Changed', 'oddNumber' => '90', 'payoutMode' => 'total'])->assertConflict();

    $this->assertDatabaseCount($game.'_odds', 1);
    $this->assertDatabaseHas($game.'_odds', ['id' => $scenario['odd']->id, 'odd_number' => $scenario['odd']->odd_number]);
})->with(['2D' => 'twod', '3D' => 'threed']);

test('cancelling a draw refunds accepted stakes once and blocks late calculation', function (string $game): void {
    $scenario = bettingScenario($game);
    Sanctum::actingAs($scenario['customer'], ['access']);
    $this->postJson('/api/betting_bets_create/'.$game, bettingPayload($scenario['number']))->assertOk();
    Sanctum::actingAs($scenario['administrator'], ['access']);
    $key = ['drawDate' => '2026-10-21', ...($game === 'twod' ? ['timeType' => 'am'] : []), 'reason' => 'Draw cancelled'];

    $this->postJson('/api/admin/betting_results_cancel/'.$game, $key)->assertOk();
    $this->postJson('/api/admin/betting_results_cancel/'.$game, $key)->assertOk();
    app(IBetService::class)->CalculateResults($game, '2026-10-21', $game === 'twod' ? 'am' : null, (string) Str::uuid7());

    $this->assertDatabaseHas('wallet', ['user_id' => $scenario['customer']->id, 'avaliable_balance' => 10000]);
    $this->assertDatabaseHas($game.'_bets', ['status' => 'cancelled']);
    $this->assertDatabaseCount('wallet_transactions', 2);
    Sanctum::actingAs($scenario['customer'], ['access']);
    $this->postJson('/api/betting_bets_create/'.$game, bettingPayload($scenario['number']))->assertConflict();
})->with(['twod', 'threed']);

test('a batch rejects invalid numbers or insufficient funds without a partial debit', function (): void {
    $scenario = bettingScenario('twod');
    Sanctum::actingAs($scenario['customer'], ['access']);
    $payload = bettingPayload('07');

    $this->postJson('/api/betting_bets_create/twod', [...$payload, 'entries' => [['number' => '07', 'stakeAmount' => '100'], ['number' => '007', 'stakeAmount' => '100']]])->assertUnprocessable()->assertJsonValidationErrors('entries.1.number');
    $this->postJson('/api/betting_bets_create/twod', [...$payload, 'entries' => [['number' => '07', 'stakeAmount' => '10001']]])->assertBadRequest();

    $this->assertDatabaseCount('twod_bets', 0);
    $this->assertDatabaseCount('wallet_transactions', 0);
});

test('customers cannot inspect another customers bets or access result and odds administration', function (): void {
    $scenario = bettingScenario('twod');
    Sanctum::actingAs($scenario['customer'], ['access']);
    $betId = $this->postJson('/api/betting_bets_create/twod', bettingPayload('07'))->assertOk()->json('data.entries.0.id');
    $other = User::query()->where('email', 'customer2@example.com')->firstOrFail();
    Sanctum::actingAs($other, ['access']);

    $this->getJson('/api/betting_bets_show/twod/'.$betId)->assertNotFound();
    $this->getJson('/api/betting_bets_list/twod?paymentUserId='.$scenario['customer']->id)->assertOk()->assertJsonCount(0, 'data.items')->assertJsonMissingPath('data.pagination');
    $this->postJson('/api/admin/betting_results_create/twod', ['drawDate' => '2026-10-21', 'timeType' => 'am', 'winningNumber' => '07'])->assertForbidden();
    $this->postJson('/api/admin/betting_odds_create/twod', [])->assertForbidden();
    $this->getJson('/api/unknown/bets')->assertNotFound();
});

test('missing payout settings return 409 and failed calculations can be retried', function (): void {
    $scenario = bettingScenario('twod');
    $scenario['odd']->update(['payout_mode' => null]);
    Sanctum::actingAs($scenario['customer'], ['access']);
    $this->postJson('/api/betting_bets_create/twod', bettingPayload('07'))->assertConflict();
    Queue::fake([CalculateBetResults::class]);
    Sanctum::actingAs($scenario['administrator'], ['access']);
    $key = ['drawDate' => '2026-10-21', 'timeType' => 'am'];
    $this->postJson('/api/admin/betting_results_create/twod', [...$key, 'winningNumber' => '07'])->assertOk();
    $draw = TwodDraw::query()->whereNotNull('winning_number')->sole();
    app(IBetService::class)->CalculationFailed('twod', '2026-10-21', 'am', $draw->result_revision);
    $this->assertDatabaseHas('twod_draws', ['id' => $draw->id, 'calculation_status' => 'failed']);

    $this->postJson('/api/admin/betting_results_recalculate/twod', $key)->assertOk();
    (new CalculateBetResults('twod', '2026-10-21', 'am', $draw->result_revision))->handle(app(IBetService::class));

    $this->assertDatabaseHas('twod_draws', ['id' => $draw->id, 'calculation_status' => 'completed']);
    Queue::assertPushed(CalculateBetResults::class, 2);
});
