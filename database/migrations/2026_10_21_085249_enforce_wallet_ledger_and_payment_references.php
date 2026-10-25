<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
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
        foreach (['transactions' => ['payment_user_id', 'type', 'reference_id'], 'withdraw_requests' => ['requested_payment_user_id', 'reference_id']] as $table => $columns) {
            if (DB::table($table)->select($columns)->groupBy(...$columns)->havingRaw('COUNT(*) > 1')->exists()) {
                throw new RuntimeException('Reconcile duplicate references in '.$table.' before adding the unique constraint.');
            }
        }
        if (DB::table('wallet_transactions')->whereNotExists(function (Builder $query): void {
            $query->selectRaw('1')->from('wallet')->whereColumn('wallet.id', 'wallet_transactions.wallet_id');
        })->exists()) {
            throw new RuntimeException('Reconcile orphaned wallet transactions before adding the foreign key.');
        }

        Schema::table('transactions', function (Blueprint $table): void {
            $table->unique(['payment_user_id', 'type', 'reference_id'], 'transactions_user_type_reference_unique');
        });
        Schema::table('withdraw_requests', function (Blueprint $table): void {
            $table->unique(['requested_payment_user_id', 'reference_id'], 'withdraw_requests_user_reference_unique');
        });
        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->foreign('wallet_id')->references('id')->on('wallet')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->dropForeign(['wallet_id']);
        });
        Schema::table('withdraw_requests', function (Blueprint $table): void {
            $table->dropUnique('withdraw_requests_user_reference_unique');
        });
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropUnique('transactions_user_type_reference_unique');
        });
    }
};
