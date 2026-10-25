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
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('payment_method_image_location')->nullable(true);
            $table->enum('type', ['deposit', 'withdraw']);
            $table->string('description', 500);
            $table->timestamps();
            $table->index(['type']);
            $table->index('created_at');
        });

        Schema::create('payment_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('type', ['withdraw', 'deposit']); // this should be come from payment_methods
            $table->string('user_name')->nullable(false);
            $table->string('phone_number')->nullable(true);
            $table->string('bank_account_number')->nullable(true);
            $table->string('descriptions')->nullable(true);
            $table->timestamps();
            $table->uuid('user_id')->nullable(false);
            $table
                ->foreign('user_id')
                ->references('id')
                ->on('users')
                ->restrictOnUpdate()
                ->restrictOnDelete();
            $table->uuid('payment_method_id')->nullable(false);
            $table
                ->foreign('payment_method_id')
                ->references('id')
                ->on('payment_methods')
                ->restrictOnDelete()
                ->restrictOnUpdate();
            $table->enum('status', ['active', 'inactive', 'delete']); // admin can change inactive but user have to change to delete
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('type', ['deposit', 'withdraw']);
            $table->string('reference_id', 50); // reference id for good.
            $table->uuid('payment_user_id')->nullable(false);
            $table
                ->foreign('payment_user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->decimal('amount', 52, 2);
            $table->dateTimeTz('paid_at');
            $table->string('last_six_tran_id');
            $table->uuid('payment_accounts_id');
            $table
                ->foreign('payment_accounts_id')
                ->references('id')
                ->on('payment_accounts')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->uuid('payment_method_id')->nullable(false);
            $table
                ->foreign('payment_method_id')
                ->references('id')
                ->on('payment_methods')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->uuid('approver_user_id')->default(null)->nullable(true);
            $table
                ->foreign('approver_user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete(); // no need on withdraw ok on withdraw
            $table->timestampTz('approved_at')->default(null)->nullable(true);
            $table->enum('status', ['pending', 'approved', 'pending_approval']);
            $table->timestamps();
        });

        Schema::create('withdraw_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference_id', 50); // this must be md5 hashed v4 uuid

            $table->decimal('amount', 52, 2);

            $table->uuid('requested_payment_user_id')->nullable(false);
            $table
                ->foreign('requested_payment_user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->enum('status', ['requested', 'approved', 'declined'])->default('requested');

            $table->uuid('approve_user_id')->nullable(true);
            $table
                ->foreign('approve_user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->uuid('declined_user_id')->nullable(true);
            $table
                ->foreign('declined_user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->timestampTz('approved_at')->nullable(true);
            $table->timestampTz('declined_at')->nullable(true);

            $table->uuid('transaction_id')->nullable(true);
            $table
                ->foreign('transaction_id')
                ->references('id')
                ->on('transactions')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->uuid('requested_payment_method_id')->nullable(false);
            $table
                ->foreign('requested_payment_method_id')
                ->references('id')
                ->on('payment_methods')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->uuid('requested_payment_account_id')->nullable(false);
            $table
                ->foreign('requested_payment_account_id')
                ->references('id')
                ->on('payment_accounts')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->timestampsTz();
        });

        Schema::create('wallet', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table
                ->foreign('user_id')
                ->references('id')
                ->on('users')
                ->restrictOnUpdate()
                ->restrictOnUpdate();
            $table->decimal('avaliable_balance')->nullable(false);
            $table->decimal('locked_balance')->nullable(false);
            $table->uuid('version');
            $table->timestamps();
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('wallet_id')->nullable(false);
            $table->enum('type', [
                'deposit',
                'bet_placed',
                'bet_won',
                'bet_refunded',
                'withdraw_lock',
                'withdraw_rejected',
                'withdraw_requested',
                'withdraw_failed',
            ]);
            $table->enum('direction', ['in', 'out']);
            $table->decimal('amount', 52, 2);
            $table->decimal('balance_before');
            $table->decimal('balance_after');
            $table->string('reference_type', 50);
            $table->uuid('reference_id');
            $table->string('idempotency_key', 64);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallet');
        Schema::dropIfExists('withdraw_requests');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('payment_accounts');
        Schema::dropIfExists('payment_methods');
    }
};
