<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->enum('type', ['deposit', 'bet_placed', 'bet_won', 'bet_refunded', 'withdraw_lock', 'withdraw_rejected', 'withdraw_requested', 'withdraw_failed', 'manual_adjustment'])->change();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reason', 700)->nullable();
            $table->uuid('client_reference')->nullable();
            $table->unique(['wallet_id', 'client_reference']);
        });
        Schema::create('admin_api_audits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('request_id')->index();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('actor_role_id')->nullable();
            $table->string('method', 10);
            $table->string('path', 1024);
            $table->string('route', 255)->nullable();
            $table->unsignedSmallInteger('status_code');
            $table->unsignedInteger('duration_ms');
            $table->ipAddress('ip_address')->nullable();
            $table->timestamp('created_at')->index();
            $table->index(['user_id', 'created_at']);
            $table->index(['status_code', 'created_at']);
        });
    }

    public function down(): void
    {
        if (DB::table('wallet_transactions')->where('type', 'manual_adjustment')->exists()) {
            throw new RuntimeException('Manual wallet adjustments exist. Preserve and reconcile their history before rollback.');
        }
        Schema::dropIfExists('admin_api_audits');
        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->dropForeign(['actor_user_id']);
            $table->dropUnique(['wallet_id', 'client_reference']);
            $table->dropColumn(['actor_user_id', 'reason', 'client_reference']);
            $table->enum('type', ['deposit', 'bet_placed', 'bet_won', 'bet_refunded', 'withdraw_lock', 'withdraw_rejected', 'withdraw_requested', 'withdraw_failed'])->change();
        });
    }
};
