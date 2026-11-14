<?php

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
    return User::factory()->create(['role_name' => 'Admin']);
}

function drawCustomer(string $game): User
{
    $customer = User::factory()->create(['role_name' => 'Customer']);
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

    $this->postJson('/api/admin/betting_draws_create/threed', drawSchedule('threed'))->assertOk()->assertJsonMissingPath('data.timeType');
    $this->postJson('/api/admin/betting_draws_create/threed', drawSchedule('threed'))->assertConflict();
    $this->postJson('/api/admin/betting_draws_create/threed', drawSchedule('threed', '2026-11-30'))->assertOk();
    $this->postJson('/api/admin/betting_draws_create/threed', drawSchedule('threed', '2026-11-24'))->assertConflict();
    $this->postJson('/api/admin/betting_draws_create/threed', drawSchedule('threed', '2026-12-03'))->assertOk();

    $this->assertDatabaseCount('threed_draws', 3);
    $this->assertDatabaseCount('admin_activities', 3);
});

test('2D draws keep independent AM and PM sessions on the same date', function (): void {
    Sanctum::actingAs(drawAdministrator(), ['access']);

    $this->postJson('/api/admin/betting_draws_create/twod', drawSchedule('twod'))->assertOk()->assertJsonPath('data.timeType', 'am');
    $this->postJson('/api/admin/betting_draws_create/twod', [...drawSchedule('twod'), 'timeType' => 'pm'])->assertOk()->assertJsonPath('data.timeType', 'pm');
    $this->postJson('/api/admin/betting_draws_create/twod', drawSchedule('twod'))->assertConflict();

    $this->assertDatabaseCount('twod_draws', 2);
});

test('saved cutoff settings are returned and reused unless a draw overrides them', function (string $game): void {
    Sanctum::actingAs(drawAdministrator(), ['access']);
    $session = $game === 'twod' ? ['timeType' => 'am'] : [];
    $settingsUrl = '/api/admin/betting_cutoff_settings_list/'.$game;
    $this->getJson($settingsUrl)->assertOk()->assertJsonPath('data.items.0.cutoffTime', null)
        ->assertJsonPath('data.timezone', config('betting.timezone'));
    $schedule = drawSchedule($game);
    unset($schedule['cutoffTime']);
    $this->postJson('/api/admin/betting_draws_create/'.$game, $schedule)->assertUnprocessable()->assertJsonValidationErrors('cutoffTime');
    $this->patchJson(str_replace('settings_list', 'settings_update', $settingsUrl), [...$session, 'cutoffTime' => '14:00'])
        ->assertOk()->assertJsonPath('data.items.0.cutoffTime', '14:00');

    $this->postJson('/api/admin/betting_draws_create/'.$game, $schedule)->assertOk()->assertJsonPath('data.cutoffTime', '14:00');
    $this->postJson('/api/admin/betting_draws_create/'.$game, [...$schedule, 'drawDate' => '2026-11-11', 'cutoffTime' => '15:00'])
        ->assertOk()->assertJsonPath('data.cutoffTime', '15:00');
    $this->getJson($settingsUrl)->assertOk()->assertJsonPath('data.items.0.cutoffTime', '14:00');
    $this->patchJson(str_replace('settings_list', 'settings_update', $settingsUrl), [...$session, 'cutoffTime' => '16:00'])->assertOk();
    $this->postJson('/api/admin/betting_draws_create/'.$game, [...$schedule, 'drawDate' => $game === 'threed' ? '2026-12-01' : '2026-11-12'])
        ->assertOk()->assertJsonPath('data.cutoffTime', '16:00');
    $this->assertDatabaseHas($game.'_draws', ['cutoff_time' => '14:00']);
    $this->assertDatabaseHas($game.'_draws', ['cutoff_time' => '15:00']);
    $this->assertDatabaseHas($game.'_draws', ['cutoff_time' => '16:00']);
})->with(['twod', 'threed']);

test('2D cutoff defaults are independent by session', function (): void {
    Sanctum::actingAs(drawAdministrator(), ['access']);
    $this->patchJson('/api/admin/betting_cutoff_settings_update/twod', ['timeType' => 'am', 'cutoffTime' => '11:55'])->assertOk();
    $this->patchJson('/api/admin/betting_cutoff_settings_update/twod', ['timeType' => 'pm', 'cutoffTime' => '15:55'])->assertOk();
    $this->getJson('/api/admin/betting_cutoff_settings_list/twod')->assertOk()
        ->assertJsonPath('data.items.0.cutoffTime', '11:55')->assertJsonPath('data.items.1.cutoffTime', '15:55');
    $schedule = drawSchedule('twod');
    unset($schedule['cutoffTime']);
    $this->postJson('/api/admin/betting_draws_create/twod', $schedule)->assertOk()->assertJsonPath('data.cutoffTime', '11:55');
    $this->postJson('/api/admin/betting_draws_create/twod', [...$schedule, 'timeType' => 'pm'])->assertOk()->assertJsonPath('data.cutoffTime', '15:55');
});

test('an administrator can change the current draw cutoff without changing its master setting', function (string $game): void {
    Sanctum::actingAs(drawAdministrator(), ['access']);
    $session = $game === 'twod' ? ['timeType' => 'am'] : [];
    $this->patchJson('/api/admin/betting_cutoff_settings_update/'.$game, [...$session, 'cutoffTime' => '14:00'])->assertOk();
    $schedule = drawSchedule($game, '2026-11-02');
    unset($schedule['cutoffTime']);
    $this->postJson('/api/admin/betting_draws_create/'.$game, $schedule)->assertOk()->assertJsonPath('data.cutoffTime', '14:00');
    $this->patchJson('/api/admin/betting_draws_cutoff_update/'.$game, [
        'drawDate' => '2026-11-02', ...$session, 'cutoffTime' => '15:00',
    ])->assertOk()->assertJsonPath('data.cutoffTime', '15:00');
    $this->getJson('/api/admin/betting_cutoff_settings_list/'.$game)->assertOk()->assertJsonPath('data.items.0.cutoffTime', '14:00');
    $this->patchJson('/api/admin/betting_draws_cutoff_update/'.$game, [
        'drawDate' => '2026-11-02', ...$session, 'cutoffTime' => '08:00',
    ])->assertConflict();
    $this->assertDatabaseHas($game.'_draws', ['cutoff_time' => '15:00']);
})->with(['twod', 'threed']);

test('a missing or completed draw cannot have its cutoff changed', function (string $game): void {
    Sanctum::actingAs(drawAdministrator(), ['access']);
    $session = $game === 'twod' ? ['timeType' => 'am'] : [];
    $endpoint = '/api/admin/betting_draws_cutoff_update/'.$game;
    $payload = ['drawDate' => '2026-11-10', ...$session, 'cutoffTime' => '15:00'];
    $this->patchJson($endpoint, $payload)->assertNotFound();
    $this->postJson('/api/admin/betting_draws_create/'.$game, drawSchedule($game))->assertOk();
    $this->postJson('/api/admin/betting_results_create/'.$game, [
        'drawDate' => '2026-11-10', ...$session, 'winningNumber' => $game === 'twod' ? '07' : '007',
    ])->assertOk();
    $this->patchJson($endpoint, $payload)->assertConflict();
    $this->assertDatabaseHas($game.'_draws', ['cutoff_time' => '14:00']);
})->with(['twod', 'threed']);

test('cutoff settings validate the session and time and require an administrator', function (): void {
    Sanctum::actingAs(drawAdministrator(), ['access']);
    $this->patchJson('/api/admin/betting_cutoff_settings_update/twod', ['timeType' => 'am', 'cutoffTime' => '24:00'])
        ->assertUnprocessable()->assertJsonValidationErrors('cutoffTime');
    $this->patchJson('/api/admin/betting_cutoff_settings_update/threed', ['timeType' => 'pm', 'cutoffTime' => '14:00'])
        ->assertUnprocessable()->assertJsonValidationErrors('timeType');
    $this->postJson('/api/admin/betting_draws_create/threed', ['drawDate' => '2026-11-10', 'openTime' => '09:00'])
        ->assertUnprocessable()->assertJsonValidationErrors('cutoffTime');
    Sanctum::actingAs(drawCustomer('twod'), ['access']);
    $this->getJson('/api/admin/betting_cutoff_settings_list/twod')->assertForbidden();
    $this->patchJson('/api/admin/betting_cutoff_settings_update/twod', ['timeType' => 'am', 'cutoffTime' => '11:55'])->assertForbidden();
    $this->patchJson('/api/admin/betting_draws_cutoff_update/twod', ['drawDate' => '2026-11-02', 'timeType' => 'am', 'cutoffTime' => '15:00'])
        ->assertForbidden();
});

test('customers only see and bet on an existing open draw', function (string $game): void {
    Sanctum::actingAs(drawAdministrator(), ['access']);
    $this->postJson('/api/admin/betting_draws_create/'.$game, drawSchedule($game))->assertOk();
    $customer = drawCustomer($game);
    Sanctum::actingAs($customer, ['access']);

    $this->getJson('/api/betting_draws_list/'.$game)->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.drawDate', '2026-11-10');
    $payload = scheduledBet($game);
    $response = $this->postJson('/api/betting_bets_create/'.$game, $payload)->assertOk()->assertJsonPath('data.entries.0.number', $game === 'twod' ? '07' : '007');
    if ($game === 'threed') {
        $response->assertJsonMissingPath('data.entries.0.timeType');
    }
    $this->travelTo(CarbonImmutable::parse('2026-11-10 14:00', config('betting.timezone')));
    $this->postJson('/api/betting_bets_create/'.$game, $payload)->assertOk()->assertJsonPath('data.batchId', $response->json('data.batchId'));
    $this->postJson('/api/betting_bets_create/'.$game, [...$payload, 'clientReference' => (string) Str::uuid7()])->assertConflict();
    $this->getJson('/api/betting_draws_list/'.$game)->assertOk()->assertJsonCount(0, 'data.items');

    $this->assertDatabaseCount($game.'_bets', 1);
    $this->assertDatabaseCount('wallet_transactions', 1);
    expect($customer->wallets()->sole()->avaliable_balance)->toBe('900.00');
})->with(['twod', 'threed']);

test('mobile betting and result lists return all records without pagination', function (): void {
    Sanctum::actingAs(drawAdministrator(), ['access']);
    $this->postJson('/api/admin/betting_draws_create/twod', drawSchedule('twod'))->assertOk();
    TwodDraw::factory()->create(['draw_date' => '2026-11-01', 'time_type' => 'am', 'winning_number' => '07']);
    TwodDraw::factory()->create(['draw_date' => '2026-11-02', 'time_type' => 'pm', 'winning_number' => '08']);
    $customer = drawCustomer('twod');
    Sanctum::actingAs($customer, ['access']);
    $payload = scheduledBet('twod');
    $payload['entries'][] = ['number' => '08', 'stakeAmount' => '100.00'];
    $this->postJson('/api/betting_bets_create/twod', $payload)->assertOk();

    $this->getJson('/api/betting_bets_list/twod?perPage=1')->assertOk()->assertJsonCount(2, 'data.items')
        ->assertJsonMissingPath('data.pagination')->assertJsonPath('data.items.0.user.id', $customer->id)
        ->assertJsonMissingPath('data.items.0.userId');
    $this->getJson('/api/betting_results_list/twod?perPage=1')->assertOk()->assertJsonCount(2, 'data.items')
        ->assertJsonMissingPath('data.pagination');
    Sanctum::actingAs(drawAdministrator(), ['access']);
    $this->getJson('/api/admin/betting_bets_list/twod?perPage=1')->assertOk()->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.pagination.total', 2);
});

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

    $this->getJson('/api/betting_draws_list/'.$game)->assertOk()->assertJsonCount(0, 'data.items');
    $this->postJson('/api/betting_bets_create/'.$game, scheduledBet($game))->assertConflict();

    $this->assertDatabaseCount($game.'_bets', 0);
    $this->assertDatabaseCount('wallet_transactions', 0);
    expect($customer->wallets()->sole()->avaliable_balance)->toBe('1000.00');
})->with(['twod', 'threed'])->with(['missing', 'not-open', 'result', 'cancelled', 'settled']);

test('3D rejects AM and PM on schedules results and settlement requests', function (string $endpoint): void {
    Sanctum::actingAs(drawAdministrator(), ['access']);
    $payload = [...drawSchedule('threed'), 'timeType' => 'pm', 'winningNumber' => '007', 'reason' => 'Cancelled'];

    $this->postJson('/api/admin/betting_'.(str_contains($endpoint, '/') ? str_replace('/', '_', $endpoint) : $endpoint.'_create').'/threed', $payload)->assertUnprocessable()->assertJsonValidationErrors('timeType');

    $this->assertDatabaseCount('threed_draws', 0);
})->with(['draws', 'results', 'results/preview', 'results/recalculate', 'results/cancel', 'results/settle']);

test('3D rejects AM and PM on customer betting and history', function (): void {
    Sanctum::actingAs(drawCustomer('threed'), ['access']);

    $this->postJson('/api/betting_bets_create/threed', [...scheduledBet('threed'), 'timeType' => 'am'])->assertUnprocessable()->assertJsonValidationErrors('timeType');
    $this->getJson('/api/betting_results_list/threed?timeType=pm')->assertUnprocessable()->assertJsonValidationErrors('timeType');

    $this->assertDatabaseCount('threed_bets', 0);
    $this->assertDatabaseCount('wallet_transactions', 0);
});

test('draw scheduling requires an administrator', function (string $game): void {
    $this->postJson('/api/admin/betting_draws_create/'.$game, drawSchedule($game))->assertUnauthorized();
    Sanctum::actingAs(drawCustomer($game), ['access']);

    $this->postJson('/api/admin/betting_draws_create/'.$game, drawSchedule($game))->assertForbidden();

    $this->assertDatabaseCount($game.'_draws', 0);
})->with(['twod', 'threed']);

test('invalid betting windows cannot create draws', function (array $window): void {
    Sanctum::actingAs(drawAdministrator(), ['access']);

    $this->postJson('/api/admin/betting_draws_create/threed', [...drawSchedule('threed', '2026-11-02'), ...$window])->assertConflict();

    $this->assertDatabaseCount('threed_draws', 0);
})->with([
    'cutoff before opening' => [['openTime' => '14:00', 'cutoffTime' => '13:59']],
    'equal opening and cutoff' => [['openTime' => '14:00', 'cutoffTime' => '14:00']],
    'cutoff already passed' => [['openTime' => '07:00', 'cutoffTime' => '08:59']],
]);

test('2D requires a session and a scheduled AM draw does not allow PM bets', function (): void {
    Sanctum::actingAs(drawAdministrator(), ['access']);
    $this->postJson('/api/admin/betting_draws_create/twod', [...drawSchedule('twod'), 'timeType' => null])->assertUnprocessable()->assertJsonValidationErrors('timeType');
    $this->postJson('/api/admin/betting_draws_create/twod', drawSchedule('twod'))->assertOk();
    Sanctum::actingAs(drawCustomer('twod'), ['access']);

    $this->postJson('/api/betting_bets_create/twod', [...scheduledBet('twod'), 'timeType' => 'pm'])->assertConflict();

    $this->assertDatabaseCount('twod_bets', 0);
    $this->assertDatabaseCount('wallet_transactions', 0);
});
