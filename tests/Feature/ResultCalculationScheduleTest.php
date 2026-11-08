<?php

use App\Http\ServiceContractor\IBetService;
use App\Jobs\CalculateBetResults;
use App\Models\Role;
use App\Models\ThreedDraw;
use App\Models\ThreedOdd;
use App\Models\TwodDraw;
use App\Models\TwodOdd;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00', config('betting.timezone')));
    $this->seed(RoleSeeder::class);
    $this->administrator = User::factory()->create(['role_id' => Role::query()->where('name', 'Admin')->sole()->id]);
    Sanctum::actingAs($this->administrator, ['access']);
});

/** @return array<string, mixed> */
function calculationKey(string $game): array
{
    return ['drawDate' => '2026-11-10', ...($game === 'twod' ? ['timeType' => 'am'] : [])];
}

function calculationDraw(string $game, string $mode = 'scheduled', string $time = '12:00'): void
{
    test()->postJson('/api/admin/'.$game.'/draws', [
        ...calculationKey($game), 'openTime' => '08:00', 'cutoffTime' => '11:00',
        'calculationMode' => $mode, ...($mode === 'scheduled' ? ['calculationTime' => $time] : []),
    ])->assertOk()->assertJsonPath('data.calculationMode', $mode);
}

test('default settings are independent and only affect subsequently scheduled draws', function (): void {
    $this->getJson('/api/admin/twod/calculation-settings')->assertOk()->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.items.0.calculationMode', 'immediate');
    $this->getJson('/api/admin/threed/calculation-settings')->assertOk()->assertJsonCount(1, 'data.items')
        ->assertJsonMissingPath('data.items.0.timeType');
    $this->patchJson('/api/admin/twod/calculation-settings', ['timeType' => 'am', 'calculationMode' => 'scheduled', 'calculationTime' => '12:01'])
        ->assertOk()->assertJsonPath('data.items.0.calculationTime', '12:01')->assertJsonPath('data.items.1.calculationMode', 'immediate');
    $schedule = ['drawDate' => '2026-11-10', 'timeType' => 'am', 'openTime' => '08:00', 'cutoffTime' => '11:00'];
    $this->postJson('/api/admin/twod/draws', $schedule)->assertOk()->assertJsonPath('data.calculationTime', '12:01')
        ->assertJsonPath('data.calculationScheduledAt', '2026-11-10T05:31:00.000000Z');
    $this->patchJson('/api/admin/twod/calculation-settings', ['timeType' => 'am', 'calculationMode' => 'manual'])->assertOk();
    $this->postJson('/api/admin/twod/draws', [...$schedule, 'drawDate' => '2026-11-11'])->assertOk()->assertJsonPath('data.calculationMode', 'manual');
    $this->postJson('/api/admin/twod/draws', [...$schedule, 'drawDate' => '2026-11-12', 'calculationMode' => 'scheduled', 'calculationTime' => '13:00'])
        ->assertOk()->assertJsonPath('data.calculationTime', '13:00');
    $this->getJson('/api/admin/threed/calculation-settings')->assertOk()->assertJsonPath('data.items.0.calculationMode', 'immediate');
    $this->assertDatabaseHas('twod_draws', ['draw_date' => '2026-11-10 00:00:00', 'calculation_time' => '12:01']);
});

test('scheduled results calculate at the configured minute without paying wallets', function (string $game): void {
    calculationDraw($game);
    $customer = User::factory()->create(['role_id' => Role::query()->where('name', 'Customer')->sole()->id]);
    $customer->wallets()->create(['avaliable_balance' => '1000.00', 'locked_balance' => '0.00', 'version' => (string) Str::uuid7()]);
    ($game === 'twod' ? TwodOdd::class : ThreedOdd::class)::factory()->create();
    $number = $game === 'twod' ? '07' : '007';
    Sanctum::actingAs($customer, ['access']);
    $this->postJson('/api/'.$game.'/bets', [...calculationKey($game), 'clientReference' => (string) Str::uuid7(), 'entries' => [
        ['number' => $number, 'stakeAmount' => '100.00'], ['number' => $game === 'twod' ? '08' : '008', 'stakeAmount' => '50.00'],
    ]])->assertOk();
    Sanctum::actingAs($this->administrator, ['access']);
    Queue::fake([CalculateBetResults::class]);
    $this->postJson('/api/admin/'.$game.'/results', [...calculationKey($game), 'winningNumber' => $number])->assertOk()->assertJsonPath('data.calculationStatus', 'pending');
    Queue::assertNothingPushed();
    $this->travelTo(CarbonImmutable::parse('2026-11-10 11:59:59', config('betting.timezone')));
    $this->artisan('betting:calculate-due-results')->assertSuccessful();
    $this->assertDatabaseHas($game.'_bets', ['draw_number' => $number, 'result_status' => 'pending']);
    $this->postJson('/api/admin/'.$game.'/results/preview', calculationKey($game))->assertConflict();
    $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00', config('betting.timezone')));
    $this->artisan('betting:calculate-due-results')->assertSuccessful();
    $this->postJson('/api/admin/'.$game.'/results/preview', calculationKey($game))->assertOk()->assertJsonPath('data.winnerCount', 1)->assertJsonPath('data.loserCount', 1);
    $this->assertDatabaseHas($game.'_draws', ['calculation_status' => 'completed', 'winning_number' => $number]);
    $this->assertDatabaseMissing('wallet_transactions', ['type' => 'bet_won']);
    expect($customer->wallets()->sole()->avaliable_balance)->toBe('850.00');
    $this->artisan('betting:calculate-due-results')->expectsOutputToContain('Processed 0')->assertSuccessful();
    expect($customer->wallets()->sole()->avaliable_balance)->toBe('850.00');
})->with(['twod', 'threed']);

test('each game can save and reuse its calculation default', function (string $game): void {
    $settings = [...($game === 'twod' ? ['timeType' => 'pm'] : []), 'calculationMode' => 'scheduled', 'calculationTime' => '15:30'];
    $response = $this->patchJson('/api/admin/'.$game.'/calculation-settings', $settings)->assertOk();
    $index = $game === 'twod' ? 1 : 0;
    $response->assertJsonPath('data.items.'.$index.'.calculationTime', '15:30');
    $this->postJson('/api/admin/'.$game.'/draws', [
        ...calculationKey($game), ...($game === 'twod' ? ['timeType' => 'pm'] : []), 'openTime' => '08:00', 'cutoffTime' => '14:00',
    ])->assertOk()->assertJsonPath('data.calculationTime', '15:30');
})->with(['twod', 'threed']);

test('calculate now bypasses manual mode and future schedules and is idempotent', function (string $game, string $mode): void {
    calculationDraw($game, $mode);
    Queue::fake([CalculateBetResults::class]);
    $key = calculationKey($game);
    $this->postJson('/api/admin/'.$game.'/results', [...$key, 'winningNumber' => $game === 'twod' ? '07' : '007'])->assertOk();
    $this->travelTo(CarbonImmutable::parse($mode === 'manual' ? '2026-11-11 12:00' : '2026-11-10 09:01', config('betting.timezone')));
    $this->artisan('betting:calculate-due-results')->expectsOutputToContain('Processed 0')->assertSuccessful();
    Queue::assertNothingPushed();
    $this->postJson('/api/admin/'.$game.'/results/calculate-now', $key)->assertOk();
    Queue::assertPushed(CalculateBetResults::class, fn (CalculateBetResults $job): bool => $job->force);
    $job = Queue::pushed(CalculateBetResults::class)->first();
    $job->handle(app(IBetService::class));
    $drawClass = $game === 'twod' ? TwodDraw::class : ThreedDraw::class;
    $calculatedAt = $drawClass::query()->sole()->calculated_at->toISOString();
    $this->travel(1)->minutes();
    $job->handle(app(IBetService::class));
    $this->postJson('/api/admin/'.$game.'/results/calculate-now', $key)->assertOk();
    expect($drawClass::query()->sole()->calculated_at->toISOString())->toBe($calculatedAt);
    Queue::assertPushed(CalculateBetResults::class, 1);
    $this->assertDatabaseCount('wallet_transactions', 0);
})->with(['twod', 'threed'])->with(['manual', 'scheduled']);

test('rescheduling a draw blocks stale automatic jobs until its new time', function (string $game): void {
    calculationDraw($game, 'immediate');
    Queue::fake([CalculateBetResults::class]);
    $key = calculationKey($game);
    $this->postJson('/api/admin/'.$game.'/results', [...$key, 'winningNumber' => $game === 'twod' ? '07' : '007'])->assertOk();
    $job = Queue::pushed(CalculateBetResults::class)->first();
    $this->patchJson('/api/admin/'.$game.'/results/calculation', [...$key, 'calculationMode' => 'scheduled', 'calculationTime' => '13:30'])
        ->assertOk()->assertJsonPath('data.calculationTime', '13:30');
    $job->handle(app(IBetService::class));
    $this->assertDatabaseHas($game.'_draws', ['calculation_status' => 'pending']);
    $this->travelTo(CarbonImmutable::parse('2026-11-10 13:00', config('betting.timezone')));
    $this->artisan('betting:calculate-due-results')->expectsOutputToContain('Processed 0')->assertSuccessful();
    $this->travelTo(CarbonImmutable::parse('2026-11-11 09:00', config('betting.timezone')));
    $this->artisan('betting:calculate-due-results')->expectsOutputToContain('Processed 1')->assertSuccessful();
    $this->assertDatabaseHas($game.'_draws', ['calculation_status' => 'completed']);
})->with(['twod', 'threed']);

test('a result can override the draw calculation mode and switching to immediate queues it', function (string $game): void {
    calculationDraw($game);
    Queue::fake([CalculateBetResults::class]);
    $key = calculationKey($game);
    $this->postJson('/api/admin/'.$game.'/results', [...$key, 'winningNumber' => $game === 'twod' ? '07' : '007', 'calculationMode' => 'manual'])
        ->assertOk()->assertJsonPath('data.calculationMode', 'manual')->assertJsonPath('data.calculationTime', null);
    Queue::assertNothingPushed();
    $this->patchJson('/api/admin/'.$game.'/results/calculation', [...$key, 'calculationMode' => 'immediate'])->assertOk();
    Queue::assertPushed(CalculateBetResults::class, 1);
})->with(['twod', 'threed']);

test('rescheduling a failed result retries at the new time and ignores old job failures', function (string $game): void {
    calculationDraw($game, 'immediate');
    Queue::fake([CalculateBetResults::class]);
    $key = calculationKey($game);
    $this->postJson('/api/admin/'.$game.'/results', [...$key, 'winningNumber' => $game === 'twod' ? '07' : '007'])->assertOk();
    $job = Queue::pushed(CalculateBetResults::class)->first();
    $job->failed(null);
    $this->assertDatabaseHas($game.'_draws', ['calculation_status' => 'failed']);
    $this->patchJson('/api/admin/'.$game.'/results/calculation', [...$key, 'calculationMode' => 'scheduled', 'calculationTime' => '12:00'])
        ->assertOk()->assertJsonPath('data.calculationStatus', 'pending');
    $job->failed(null);
    $this->assertDatabaseHas($game.'_draws', ['calculation_status' => 'pending']);
    $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00', config('betting.timezone')));
    $this->artisan('betting:calculate-due-results')->expectsOutputToContain('Processed 1')->assertSuccessful();
    $this->assertDatabaseHas($game.'_draws', ['calculation_status' => 'completed']);
})->with(['twod', 'threed']);

test('a queued automatic job respects switching to manual mode', function (string $game): void {
    calculationDraw($game, 'immediate');
    Queue::fake([CalculateBetResults::class]);
    $key = calculationKey($game);
    $this->postJson('/api/admin/'.$game.'/results', [...$key, 'winningNumber' => $game === 'twod' ? '07' : '007'])->assertOk();
    $job = Queue::pushed(CalculateBetResults::class)->first();
    $this->patchJson('/api/admin/'.$game.'/results/calculation', [...$key, 'calculationMode' => 'manual'])->assertOk();
    $job->handle(app(IBetService::class));
    $this->travel(1)->days();
    $this->artisan('betting:calculate-due-results')->expectsOutputToContain('Processed 0')->assertSuccessful();
    $this->assertDatabaseHas($game.'_draws', ['calculation_status' => 'pending']);
})->with(['twod', 'threed']);

test('due calculation waits for a winning number and late results are queued immediately', function (string $game): void {
    calculationDraw($game);
    Queue::fake([CalculateBetResults::class]);
    $this->travelTo(CarbonImmutable::parse('2026-11-10 12:30', config('betting.timezone')));
    $this->artisan('betting:calculate-due-results')->expectsOutputToContain('Processed 0')->assertSuccessful();
    $this->postJson('/api/admin/'.$game.'/results', [...calculationKey($game), 'winningNumber' => $game === 'twod' ? '07' : '007'])->assertOk();
    Queue::assertPushed(CalculateBetResults::class, 1);
})->with(['twod', 'threed']);

test('calculation settings validate mode time and game session', function (array $data, string $field): void {
    $this->patchJson('/api/admin/twod/calculation-settings', ['timeType' => 'am', ...$data])->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'missing mode' => [[], 'calculationMode'],
    'invalid mode' => [['calculationMode' => 'other'], 'calculationMode'],
    'missing time' => [['calculationMode' => 'scheduled'], 'calculationTime'],
    'invalid hour' => [['calculationMode' => 'scheduled', 'calculationTime' => '24:00'], 'calculationTime'],
    'seconds' => [['calculationMode' => 'scheduled', 'calculationTime' => '12:00:00'], 'calculationTime'],
    'unexpected time' => [['calculationMode' => 'manual', 'calculationTime' => '12:00'], 'calculationTime'],
    'missing 2D session' => [['calculationMode' => 'manual', 'timeType' => null], 'timeType'],
]);

test('3D calculation settings reject AM and PM', function (): void {
    $this->patchJson('/api/admin/threed/calculation-settings', ['timeType' => 'pm', 'calculationMode' => 'manual'])->assertUnprocessable()->assertJsonValidationErrors('timeType');
    $this->patchJson('/api/admin/threed/results/calculation', [...calculationKey('threed'), 'timeType' => 'pm', 'calculationMode' => 'manual'])
        ->assertUnprocessable()->assertJsonValidationErrors('timeType');
    $this->postJson('/api/admin/threed/results/calculate-now', [...calculationKey('threed'), 'timeType' => 'pm'])->assertUnprocessable()->assertJsonValidationErrors('timeType');
});

test('calculation controls require an administrator', function (string $game): void {
    Sanctum::actingAs(User::factory()->create(['role_id' => Role::query()->where('name', 'Customer')->sole()->id]), ['access']);
    $this->getJson('/api/admin/'.$game.'/calculation-settings')->assertForbidden();
    $this->patchJson('/api/admin/'.$game.'/calculation-settings', ['calculationMode' => 'manual'])->assertForbidden();
    $this->patchJson('/api/admin/'.$game.'/results/calculation', [...calculationKey($game), 'calculationMode' => 'manual'])->assertForbidden();
    $this->postJson('/api/admin/'.$game.'/results/calculate-now', calculationKey($game))->assertForbidden();
    $this->assertDatabaseMissing('result_calculation_settings', ['calculation_mode' => 'manual']);
})->with(['twod', 'threed']);

test('missing results cannot calculate and completed draws cannot change schedules', function (string $game, string $state): void {
    $key = calculationKey($game);
    if ($state === 'missing') {
        $this->patchJson('/api/admin/'.$game.'/results/calculation', [...$key, 'calculationMode' => 'manual'])->assertNotFound();
        $this->postJson('/api/admin/'.$game.'/results/calculate-now', $key)->assertNotFound();

        return;
    }
    calculationDraw($game);
    $this->postJson('/api/admin/'.$game.'/results/calculate-now', $key)->assertNotFound();
    $drawClass = $game === 'twod' ? TwodDraw::class : ThreedDraw::class;
    $draw = $drawClass::query()->sole();
    $draw->forceFill(['winning_number' => $game === 'twod' ? '07' : '007', 'result_revision' => (string) Str::uuid7(), ...match ($state) {
        'calculated' => ['calculation_status' => 'completed'],
        'settled' => ['settled_at' => now('UTC')],
        'cancelled' => ['cancelled_at' => now('UTC')],
    }])->save();
    $this->patchJson('/api/admin/'.$game.'/results/calculation', [...$key, 'calculationMode' => 'manual'])->assertConflict();
    $this->artisan('betting:calculate-due-results')->expectsOutputToContain('Processed 0')->assertSuccessful();
    if ($state !== 'calculated') {
        $this->postJson('/api/admin/'.$game.'/results/calculate-now', $key)->assertConflict();
        (new CalculateBetResults($game, $key['drawDate'], $key['timeType'] ?? null, $draw->result_revision, true))->handle(app(IBetService::class));
        expect($draw->refresh()->calculation_status)->toBe('pending');
    }
})->with(['twod', 'threed'])->with(['missing', 'calculated', 'settled', 'cancelled']);

test('calculation migration preserves existing results and can roll back', function (): void {
    $draw = TwodDraw::factory()->create(['winning_number' => '07']);
    $migration = require database_path('migrations/2026_11_02_110226_add_result_calculation_schedules.php');
    $migration->down();
    expect(Schema::hasTable('result_calculation_settings'))->toBeFalse();
    $migration->up();
    expect($draw->refresh()->winning_number)->toBe('07')->and($draw->calculation_mode)->toBe('immediate');
    expect(DB::table('result_calculation_settings')->count())->toBe(3);
});
