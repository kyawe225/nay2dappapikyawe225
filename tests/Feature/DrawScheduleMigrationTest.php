<?php

use App\Models\ThreedDraw;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->originalDefault = config('database.default');
    config(['database.connections.upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true], 'database.default' => 'upgrade']);
    $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn (string $path): bool => ! str_starts_with(basename($path), '2026_11_02_') && ! str_starts_with(basename($path), '2026_11_12_085945_')));
    $this->artisan('migrate', ['--database' => 'upgrade', '--path' => $paths, '--realpath' => true, '--force' => true, '--no-interaction' => true])->assertExitCode(0);
});

afterEach(function (): void {
    DB::purge('upgrade');
    config(['database.default' => $this->originalDefault]);
});

test('upgrading to date-only 3D draws preserves existing results', function (): void {
    $draw = ThreedDraw::factory()->create(['draw_date' => '2026-10-21', 'time_type' => 'pm', 'winning_number' => '007']);

    $this->artisan('migrate', ['--database' => 'upgrade', '--force' => true, '--no-interaction' => true])->assertExitCode(0);

    $this->assertDatabaseHas('threed_draws', ['id' => $draw->id, 'winning_number' => '007']);
    expect(Schema::hasColumn('threed_draws', 'time_type'))->toBeFalse();
    expect(Schema::hasColumn('threed_bets', 'time'))->toBeFalse();
    expect(Schema::hasColumn('admin_activities', 'actor_role_name'))->toBeTrue();
});

test('migration refuses conflicting AM and PM results without losing either record', function (): void {
    foreach (['am', 'pm'] as $timeType) {
        ThreedDraw::factory()->create(['draw_date' => '2026-10-21', 'time_type' => $timeType]);
    }
    $migration = require database_path('migrations/2026_11_02_104225_use_date_only_threed_draws_and_scheduled_betting.php');

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Multiple 3D draws exist on the same date.');

    $this->assertDatabaseCount('threed_draws', 2);
    expect(Schema::hasColumn('threed_draws', 'time_type'))->toBeTrue();
    expect(Schema::hasColumn('twod_draws', 'open_date'))->toBeFalse();
});
