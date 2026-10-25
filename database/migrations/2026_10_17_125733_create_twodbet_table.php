<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('twod_draws', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->default(null)->nullable(); // this can be some kind of datestring
            $table->date('draw_date')->nullable(false);
            $table->enum('time_type', ['am', 'pm']);
            $table->string('winning_number', 2)->nullable(false);
            $table->decimal('odds')->default(85);
            $table->softDeletes();
            $table->timestamps();
            $table->index('draw_date');
            $table->index('created_at');
            $table->index(['draw_date', 'created_at']);
        });
        Schema::create('twod_bets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->default(null)->nullable(); // this can be some kind of datestring
            $table->date('draw_date')->nullable(false);
            $table->enum('time', ['am', 'pm']);
            $table->string('draw_number', 2);
            $table->enum('status', ['active', 'settled', 'cancelled', 'lost']);
            $table->uuid('user_id')->nullable(false);
            $table->decimal('stake_amount');
            $table->decimal('potential_payout');
            $table->decimal('settle_amount');
            $table
                ->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->timestamps();
            $table->index('draw_date');
            $table->index('created_at');
            $table->index(['draw_date', 'created_at']);
        });

        Schema::create('twod_odds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->decimal('odd_number');
            $table->date('start_date');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('twod_bets');
        Schema::dropIfExists('twod_odds');
        Schema::dropIfExists('twod_draws');
    }
};
