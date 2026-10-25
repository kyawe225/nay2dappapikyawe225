<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'audit';

    public function up(): void
    {
        Schema::connection('audit')->create('admin_activities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->uuid('actor_role_id')->nullable();
            $table->string('action', 100);
            $table->string('target_type', 100);
            $table->uuid('target_id')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });
        Schema::connection('audit')->create('admin_api_audits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('request_id')->index();
            $table->uuid('user_id');
            $table->uuid('actor_role_id');
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
        Schema::connection('audit')->dropIfExists('admin_api_audits');
        Schema::connection('audit')->dropIfExists('admin_activities');
    }
};
