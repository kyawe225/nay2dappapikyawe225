<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->enum('status', ['active', 'suspended'])->default('active')->index();
        });
        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->enum('status', ['active', 'inactive'])->default('active');
        });
        Schema::create('betting_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->enum('game', ['twod', 'threed']);
            $table->date('draw_date');
            $table->enum('time_type', ['am', 'pm']);
            $table->timestamps();
            $table->unique(['game', 'draw_date', 'time_type']);
        });
        Schema::create('admin_activities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 100);
            $table->string('target_type', 100);
            $table->uuid('target_id')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });
        foreach (['twod', 'threed'] as $game) {
            Schema::table($game.'_odds', function (Blueprint $table): void {
                $table->decimal('odd_number', 20, 4)->change();
                $table->enum('payout_mode', ['total', 'profit'])->nullable();
                $table->unique('start_date');
            });
            Schema::table($game.'_draws', function (Blueprint $table) use ($game): void {
                $table->string('winning_number', $game === 'twod' ? 2 : 3)->nullable()->change();
                if ($game === 'threed') {
                    $table->string('open_time')->nullable()->change();
                    $table->string('cutoff_time')->nullable()->change();
                }
                $table->enum('calculation_status', ['pending', 'completed', 'failed'])->default('pending');
                $table->uuid('result_revision')->nullable();
                $table->timestampTz('calculated_at')->nullable();
                $table->timestampTz('settled_at')->nullable();
                $table->timestampTz('cancelled_at')->nullable();
                $table->foreignUuid('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->foreignUuid('settled_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->string('cancellation_reason', 700)->nullable();
                $table->unique(['draw_date', 'time_type']);
            });
            Schema::table($game.'_bets', function (Blueprint $table) use ($game): void {
                $table->decimal('stake_amount', 52, 2)->change();
                $table->decimal('potential_payout', 52, 2)->change();
                $table->decimal('settle_amount', 52, 2)->change();
                $table->foreignUuid('odd_id')->nullable()->constrained($game.'_odds')->restrictOnDelete();
                $table->decimal('accepted_odds', 20, 4)->nullable();
                $table->uuid('batch_id')->nullable()->index();
                $table->uuid('client_reference')->nullable();
                $table->string('request_hash', 64)->nullable();
                $table->unsignedInteger('entry_index')->default(0);
                $table->enum('result_status', ['pending', 'won', 'lost'])->default('pending');
                $table->timestampTz('calculated_at')->nullable();
                $table->timestampTz('settled_at')->nullable();
                $table->unique(['user_id', 'client_reference', 'entry_index'], $game.'_bets_request_unique');
                $table->index(['draw_date', 'time', 'status'], $game.'_bets_session_index');
            });
        }
    }

    public function down(): void
    {
        foreach (['twod', 'threed'] as $game) {
            Schema::table($game.'_bets', function (Blueprint $table) use ($game): void {
                $table->dropForeign(['odd_id']);
                $table->dropUnique($game.'_bets_request_unique');
                $table->dropIndex($game.'_bets_session_index');
                $table->dropIndex(['batch_id']);
                $table->dropColumn(['odd_id', 'accepted_odds', 'batch_id', 'client_reference', 'request_hash', 'entry_index', 'result_status', 'calculated_at', 'settled_at']);
            });
            Schema::table($game.'_draws', function (Blueprint $table): void {
                foreach (['recorded_by', 'settled_by', 'cancelled_by'] as $field) {
                    $table->dropForeign([$field]);
                }
                $table->dropUnique(['draw_date', 'time_type']);
                $table->dropColumn(['calculation_status', 'result_revision', 'calculated_at', 'settled_at', 'cancelled_at', 'recorded_by', 'settled_by', 'cancelled_by', 'cancellation_reason']);
            });
            Schema::table($game.'_odds', function (Blueprint $table): void {
                $table->dropUnique(['start_date']);
                $table->dropColumn('payout_mode');
            });
        }
        Schema::dropIfExists('admin_activities');
        Schema::dropIfExists('betting_sessions');
        Schema::table('payment_methods', fn (Blueprint $table) => $table->dropColumn('status'));
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropColumn('status');
        });
    }
};
