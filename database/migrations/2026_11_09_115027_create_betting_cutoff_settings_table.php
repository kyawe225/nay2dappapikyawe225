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
        Schema::create('betting_cutoff_settings', function (Blueprint $table): void {
            $table->string('game', 10);
            $table->string('time_type', 4);
            $table->string('cutoff_time', 5)->nullable();
            $table->timestamps();
            $table->primary(['game', 'time_type']);
        });

        foreach (['twod' => ['am', 'pm'], 'threed' => ['draw']] as $game => $sessions) {
            foreach ($sessions as $session) {
                DB::table('betting_cutoff_settings')->insert([
                    'game' => $game, 'time_type' => $session,
                    'created_at' => now('UTC'), 'updated_at' => now('UTC'),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('betting_cutoff_settings');
    }
};
