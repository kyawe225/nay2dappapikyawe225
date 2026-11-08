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
        if (DB::table('threed_draws')->select('draw_date')->groupBy('draw_date')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Multiple 3D draws exist on the same date. Reconcile their results before migrating to date-only draws.');
        }

        Schema::table('twod_draws', function (Blueprint $table): void {
            $table->date('open_date')->nullable();
            $table->string('open_time')->nullable();
            $table->string('cutoff_time')->nullable();
        });
        Schema::table('threed_draws', function (Blueprint $table): void {
            $table->dropUnique(['draw_date', 'time_type']);
            $table->dropColumn('time_type');
            $table->unique('draw_date');
            $table->date('open_date')->nullable();
        });
        Schema::table('threed_bets', function (Blueprint $table): void {
            $table->dropIndex('threed_bets_session_index');
            $table->dropColumn('time');
            $table->index(['draw_date', 'status'], 'threed_bets_date_status_index');
        });
        Schema::table('betting_sessions', function (Blueprint $table): void {
            $table->enum('time_type', ['am', 'pm', 'draw'])->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('threed_draws')->exists() || DB::table('threed_bets')->exists()) {
            throw new RuntimeException('Date-only 3D history exists. Reconcile it before restoring AM/PM sessions.');
        }

        Schema::table('threed_draws', function (Blueprint $table): void {
            $table->dropUnique(['draw_date']);
            $table->enum('time_type', ['am', 'pm']);
            $table->unique(['draw_date', 'time_type']);
            $table->dropColumn('open_date');
        });
        Schema::table('threed_bets', function (Blueprint $table): void {
            $table->dropIndex('threed_bets_date_status_index');
            $table->enum('time', ['am', 'pm']);
            $table->index(['draw_date', 'time', 'status'], 'threed_bets_session_index');
        });
        DB::table('betting_sessions')->where('time_type', 'draw')->delete();
        Schema::table('betting_sessions', function (Blueprint $table): void {
            $table->enum('time_type', ['am', 'pm'])->change();
        });
        Schema::table('twod_draws', function (Blueprint $table): void {
            $table->dropColumn(['open_date', 'open_time', 'cutoff_time']);
        });
    }
};
