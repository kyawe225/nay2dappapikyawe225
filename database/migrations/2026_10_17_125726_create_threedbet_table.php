<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create("threed_draws", function (Blueprint $table) {
            $table->uuid("id")->primary();
            $table->string("name")->default(null)->nullable(); // this can be some kind of datestring
            $table->date("draw_date")->nullable(false);
            $table->enum("time_type", ["am", "pm"]);
            $table->string("open_time"); // these will be in time format 24 hour format
            $table->string("cutoff_time"); // these will be in time format 24 hour format
            $table->string("winning_number", 3)->nullable(true);
            $table->decimal("odds")->default(550);
            $table->softDeletes();
            $table->timestamps();
            $table->index("draw_date");
            $table->index("created_at");
            $table->index(["draw_date", "created_at"]);
        });

        Schema::create("threed_bets", function (Blueprint $table) {
            $table->uuid("id")->primary();
            $table->string("name")->default(null)->nullable(); // this can be some kind of datestring
            $table->date("draw_date")->nullable(false);
            $table->enum("time", ["am", "pm"]);
            $table->string("draw_number", 3);
            $table->decimal("stake_amount");
            $table->decimal("potential_payout");
            $table->decimal("settle_amount");
            $table->enum("status", ["active", "settled", "cancelled", "lost"]);
            $table->uuid("user_id")->nullable(false);
            $table
                ->foreign("user_id")
                ->references("id")
                ->on("users")
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->timestamps();
            $table->index("draw_date");
            $table->index("created_at");
            $table->index(["draw_date", "created_at"]);
        });

        Schema::create("threed_odds", function (Blueprint $table) {
            $table->uuid("id")->primary();
            $table->string("name");
            $table->decimal('odd_number');
            $table->date("start_date");
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists("threed_bets");
        Schema::dropIfExists("threed_odds");
        Schema::dropIfExists("threed_draws");
    }
};
