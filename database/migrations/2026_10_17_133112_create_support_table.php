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
        Schema::create('support_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->nullable(false);
            $table->string('image_location')->nullable(false);
            $table->timestamps();
        });

        Schema::create('supports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('support_type_id')->nullable(false);
            $table
                ->foreign('support_type_id')
                ->references('id')
                ->on('support_types')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->string('name')->nullable(false);
            $table->enum('type', ['link', 'phone', 'other']);
            $table->string('value', 700)->nullable(true);
            $table->enum('status', ['active', 'inactive']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supports');
        Schema::dropIfExists('support_types');
    }
};
