<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->enum('status', ['pending', 'approved', 'pending_approval', 'rejected'])->change();
            $table->foreignUuid('declined_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('declined_at')->nullable();
        });

        Schema::table('wallet', function (Blueprint $table): void {
            $table->decimal('avaliable_balance', 52, 2)->change();
            $table->decimal('locked_balance', 52, 2)->change();
            $table->unique('user_id');
        });

        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->decimal('balance_before', 52, 2)->change();
            $table->decimal('balance_after', 52, 2)->change();
            $table->unique('idempotency_key');
            $table->index(['wallet_id', 'created_at']);
        });
    }

    public function down(): void
    {
        if (DB::table('transactions')->where('status', 'rejected')->exists()
            || DB::table('wallet')->where('avaliable_balance', '>', '999999.99')->orWhere('locked_balance', '>', '999999.99')->exists()
            || DB::table('wallet_transactions')->where('balance_before', '>', '999999.99')->orWhere('balance_after', '>', '999999.99')->exists()) {
            throw new RuntimeException('Reconcile rejected deposits and balances exceeding the old precision before rolling back this migration.');
        }

        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->dropUnique(['idempotency_key']);
            $table->dropIndex(['wallet_id', 'created_at']);
            $table->decimal('balance_before', 8, 2)->change();
            $table->decimal('balance_after', 8, 2)->change();
        });

        Schema::table('wallet', function (Blueprint $table): void {
            $table->dropUnique(['user_id']);
            $table->decimal('avaliable_balance', 8, 2)->change();
            $table->decimal('locked_balance', 8, 2)->change();
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropForeign(['declined_user_id']);
            $table->dropColumn(['declined_user_id', 'declined_at']);
            $table->enum('status', ['pending', 'approved', 'pending_approval'])->change();
        });
    }
};
