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
        foreach (['twod', 'threed'] as $game) {
            Schema::table($game.'_odds', function (Blueprint $table): void {
                $table->dropUnique(['start_date']);
                $table->index(['created_at', 'id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['twod', 'threed'] as $game) {
            if (DB::table($game.'_odds')->select('start_date')->groupBy('start_date')->havingRaw('COUNT(*) > 1')->exists()) {
                throw new RuntimeException('Reconcile duplicate start dates in '.$game.'_odds before restoring the unique constraint.');
            }
        }
        foreach (['twod', 'threed'] as $game) {
            Schema::table($game.'_odds', function (Blueprint $table): void {
                $table->dropIndex(['created_at', 'id']);
                $table->unique('start_date');
            });
        }
    }
};
