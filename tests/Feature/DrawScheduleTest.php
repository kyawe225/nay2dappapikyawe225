<?php

use App\Models\Role;
use App\Models\ThreedDraw;
use App\Models\ThreedOdd;
use App\Models\TwodDraw;
use App\Models\TwodOdd;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00', config('betting.timezone')));
    $this->seed(RoleSeeder::class);
});

function drawAdministrator(): User
{
    return User::factory()->create(['role_id' => Role::query()->where('name', 'Admin')->sole()->id]);
}

function drawCustomer(string $game): User
{
    $customer = User::factory()->create(['role_id' => Role::query()->where('name', 'Customer')->sole()->id]);
    $customer->wallets()->create(['avaliable_balance' => '1000.00', 'locked_balance' => '0.00', 'version' => (string) Str::uuid7()]);
    ($game === 'twod' ? TwodOdd::class : ThreedOdd::class)::factory()->create();

    return $customer;
}

/** @return array<string, mixed> */
function drawSchedule(string $game, string $date = '2026-11-10'): array
{
    return ['drawDate' => $date, 'openDate' => '2026-11-02', 'openTime' => '09:00', 'cutoffTime' => '14:00', ...($game === 'twod' ? ['timeType' => 'am'] : [])];
}

/** @return array<string, mixed> */
function scheduledBet(string $game): array
{
    return ['drawDate' => '2026-11-10', ...($game === 'twod' ? ['timeType' => 'am'] : []), 'clientReference' => (string) Str::uuid7(), 'entries' => [['number' => $game === 'twod' ? '07' : '007', 'stakeAmount' => '100.00']]];
}

test('administrators choose at most two distinct 3D dates per month', function (): void {
    Sanctum::actingAs(drawAdministrator(), ['access']);

    $this->postJson('/api/admin/threed/draws', drawSchedule('threed'))->assertOk()->assertJsonMissingPath('data.timeType');
    $this->postJson('/api/admin/threed/draws', drawSchedule('threed'))->assertConflict();
    $this->postJson('/api/admin/threed/draws', drawSchedule('threed', '2026-11-30'))->assertOk();
    $this->postJson('/api/admin/threed/draws', drawSchedule('threed', '2026-11-24'))->assertConflict();
    $this->postJson('/api/admin/threed/draws', drawSchedule('threed', '2026-12-03'))->assertOk();

    $this->assertDatabaseCount('threed_draws', 3);
    $this->assertDatabaseCount('admin_activities', 3);
});

test('2D draws keep independent AM and PM sessions on the same date', function (): void {
    Sanctum::actingAs(drawAdministrator(), ['access']);

    $this->postJson('/api/admin/twod/draws', drawSchedule('twod'))->assertOk()->assertJsonPath('data.timeType', 'am');
    $this->postJson('/api/admin/twod/draws', [...drawSchedule('twod'), 'timeType' => 'pm'])->assertOk()->assertJsonPath('data.timeType', 'pm');
    $this->postJson('/api/admin/twod/draws', drawSchedule('twod'))->assertConflict();

    $this->assertDatabaseCount('twod_draws', 2);
});

test('customers only see and bet on an existing open draw', function (string $game): void {
    Sanctum::actingAs(drawAdministrator(), ['access']);
    $this->postJson('/api/admin/'.$game.'/draws', drawSchedule($game))->assertOk();
    $customer = drawCustomer($game);
    Sanctum::actingAs($customer, ['access']);

    $this->getJson('/api/'.$game.'/draws')->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.drawDate', '2026-11-10');
    $payload = scheduledBet($game);
    $response = $this->postJson('/api/'.$game.'/bets', $payload)->assertOk()->assertJsonPath('data.entries.0.number', $game === 'twod' ? '07' : '007');
    if ($game === 'threed') {
        $response->assertJsonMissingPath('data.entries.0.timeType');
    }
    $this->travelTo(CarbonImmutable::parse('2026-11-10 14:00', config('betting.timezone')));
    $this->postJson('/api/'.$game.'/bets', $payload)->assertOk()->assertJsonPath('data.batchId', $response->json('data.batchId'));
    $this->postJson('/api/'.$game.'/bets', [...$payload, 'clientReference' => (string) Str::uuid7()])->assertConflict();
    $this->getJson('/api/'.$game.'/draws')->assertOk()->assertJsonCount(0, 'data.items');

    $this->assertDatabaseCount($game.'_bets', 1);
    $this->assertDatabaseCount('wallet_transactions', 1);
    expect($customer->wallets()->sole()->avaliable_balance)->toBe('900.00');
})->with(['twod', 'threed']);

test('missing and closed draws return 409 without debiting the wallet', function (string $game, string $state): void {
    $customer = drawCustomer($game);
    if ($state !== 'missing') {
        ($game === 'twod' ? TwodDraw::class : ThreedDraw::class)::factory()->create([
            'draw_date' => '2026-11-10', 'open_date' => $state === 'not-open' ? '2026-11-03' : '2026-11-02',
            'open_time' => '09:00', 'cutoff_time' => '14:00',
            'winning_number' => $state === 'result' ? ($game === 'twod' ? '07' : '007') : null,
            'cancelled_at' => $state === 'cancelled' ? now() : null,
            'settled_at' => $state === 'settled' ? now() : null,
            ...($game === 'twod' ? ['time_type' => 'am'] : []),
        ]);
    }
    Sanctum::actingAs($customer, ['access']);

    $this->getJson('/api/'.$game.'/draws')->assertOk()->assertJsonCount(0, 'data.items');
    $this->postJson('/api/'.$game.'/bets', scheduledBet($game))->assertConflict();

    $this->assertDatabaseCount($game.'_bets', 0);
    $this->assertDatabaseCount('wallet_transactions', 0);
    expect($customer->wallets()->sole()->avaliable_balance)->toBe('1000.00');
})->with(['twod', 'threed'])->with(['missing', 'not-open', 'result', 'cancelled', 'settled']);

test('3D rejects AM and PM on schedules results and settlement requests', function (string $endpoint): void {
    Sanctum::actingAs(drawAdministrator(), ['access']);
    $payload = [...drawSchedule('threed'), 'timeType' => 'pm', 'winningNumber' => '007', 'reason' => 'Cancelled'];

    $this->postJson('/api/admin/threed/'.$endpoint, $payload)->assertUnprocessable()->assertJsonValidationErrors('timeType');

    $this->assertDatabaseCount('threed_draws', 0);
})->with(['draws', 'results', 'results/preview', 'results/recalculate', 'results/cancel', 'results/settle']);

test('3D rejects AM and PM on customer betting and history', function (): void {
    Sanctum::actingAs(drawCustomer('threed'), ['access']);

    $this->postJson('/api/threed/bets', [...scheduledBet('threed'), 'timeType' => 'am'])->assertUnprocessable()->assertJsonValidationErrors('timeType');
    $this->getJson('/api/threed/results?timeType=pm')->assertUnprocessable()->assertJsonValidationErrors('timeType');

    $this->assertDatabaseCount('threed_bets', 0);
    $this->assertDatabaseCount('wallet_transactions', 0);
});

test('draw scheduling requires an administrator', function (string $game): void {
    $this->postJson('/api/admin/'.$game.'/draws', drawSchedule($game))->assertUnauthorized();
    Sanctum::actingAs(drawCustomer($game), ['access']);

    $this->postJson('/api/admin/'.$game.'/draws', drawSchedule($game))->assertForbidden();

    $this->assertDatabaseCount($game.'_draws', 0);
})->with(['twod', 'threed']);

test('invalid betting windows cannot create draws', function (array $window): void {
    Sanctum::actingAs(drawAdministrator(), ['access']);

    $this->postJson('/api/admin/threed/draws', [...drawSchedule('threed', '2026-11-02'), ...$window])->assertConflict();

    $this->assertDatabaseCount('threed_draws', 0);
})->with([
    'cutoff before opening' => [['openTime' => '14:00', 'cutoffTime' => '13:59']],
    'equal opening and cutoff' => [['openTime' => '14:00', 'cutoffTime' => '14:00']],
    'cutoff already passed' => [['openTime' => '07:00', 'cutoffTime' => '08:59']],
]);

test('2D requires a session and a scheduled AM draw does not allow PM bets', function (): void {
    Sanctum::actingAs(drawAdministrator(), ['access']);
    $this->postJson('/api/admin/twod/draws', [...drawSchedule('twod'), 'timeType' => null])->assertUnprocessable()->assertJsonValidationErrors('timeType');
    $this->postJson('/api/admin/twod/draws', drawSchedule('twod'))->assertOk();
    Sanctum::actingAs(drawCustomer('twod'), ['access']);

    $this->postJson('/api/twod/bets', [...scheduledBet('twod'), 'timeType' => 'pm'])->assertConflict();

    $this->assertDatabaseCount('twod_bets', 0);
    $this->assertDatabaseCount('wallet_transactions', 0);
});
