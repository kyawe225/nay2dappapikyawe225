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
        $roles = DB::table('roles')->get(['id', 'name', 'status', 'created_at', 'updated_at', 'deleted_at']);
        $roleNames = $roles->pluck('name', 'id');

        foreach (['admin_activities', 'admin_api_audits'] as $auditTable) {
            if (Schema::hasTable($auditTable) && DB::table($auditTable)->whereNotNull('actor_role_id')->whereNotIn('actor_role_id', $roleNames->keys()->all())->exists()) {
                throw new RuntimeException('Reconcile unknown role IDs in '.$auditTable.' before changing the role key.');
            }
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('role_name')->nullable();
        });

        foreach ($roleNames as $roleId => $roleName) {
            DB::table('users')->where('role_id', $roleId)->update(['role_name' => $roleName]);
        }

        if (DB::table('users')->whereNull('role_name')->exists()) {
            throw new RuntimeException('Reconcile users with unknown roles before changing the role key.');
        }

        foreach (['admin_activities', 'admin_api_audits'] as $auditTable) {
            if (! Schema::hasTable($auditTable)) {
                continue;
            }

            Schema::table($auditTable, function (Blueprint $table): void {
                $table->string('actor_role_name')->nullable();
            });

            foreach ($roleNames as $roleId => $roleName) {
                DB::table($auditTable)->where('actor_role_id', $roleId)->update(['actor_role_name' => $roleName]);
            }

            Schema::table($auditTable, function (Blueprint $table): void {
                $table->dropColumn('actor_role_id');
            });
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['role_id']);
            $table->dropColumn('role_id');
        });

        Schema::create('roles_by_name', function (Blueprint $table): void {
            $table->string('role_name')->primary();
            $table->enum('status', ['active', 'inactive']);
            $table->timestamps();
            $table->softDeletes();
        });

        foreach ($roles as $role) {
            DB::table('roles_by_name')->insert([
                'role_name' => $role->name,
                'status' => $role->status,
                'created_at' => $role->created_at,
                'updated_at' => $role->updated_at,
                'deleted_at' => $role->deleted_at,
            ]);
        }

        Schema::drop('roles');
        Schema::rename('roles_by_name', 'roles');

        Schema::table('users', function (Blueprint $table): void {
            $table->string('role_name')->nullable(false)->change();
            $table->foreign('role_name')->references('role_name')->on('roles')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Role UUIDs were removed; restore a database backup to reverse this migration.');
    }
};
