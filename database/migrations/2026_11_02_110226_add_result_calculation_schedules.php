<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('result_calculation_settings', function (Blueprint $table): void {
            $table->string('game', 10);
            $table->string('time_type', 4);
            $table->string('calculation_mode', 10)->default('immediate');
            $table->string('calculation_time', 5)->nullable();
            $table->timestamps();
            $table->primary(['game', 'time_type']);
        });
        foreach (['twod' => ['am', 'pm'], 'threed' => ['draw']] as $game => $sessions) {
            foreach ($sessions as $session) {
                DB::table('result_calculation_settings')->insert([
                    'game' => $game, 'time_type' => $session, 'calculation_mode' => 'immediate',
                    'created_at' => now('UTC'), 'updated_at' => now('UTC'),
                ]);
            }
        }
        foreach (['twod_draws', 'threed_draws'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('calculation_mode', 10)->default('immediate');
                $table->string('calculation_time', 5)->nullable();
                $table->dateTime('calculation_scheduled_at')->nullable()->index();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['twod_draws', 'threed_draws'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropIndex(['calculation_scheduled_at']);
                $table->dropColumn(['calculation_mode', 'calculation_time', 'calculation_scheduled_at']);
            });
        }
        Schema::dropIfExists('result_calculation_settings');
    }
};
