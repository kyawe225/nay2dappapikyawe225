<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Service\AuditDatabase;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SetupAuditDatabase extends Command
{
    protected $signature = 'audit:setup {--import-legacy : Copy administrator-only historical rows without deleting source records} {--force : Run migrations in production}';

    protected $description = 'Initialize the separate administrator audit database and optionally import legacy audits';

    public function handle(AuditDatabase $database): int
    {
        try {
            $connection = $database->Connection();
            if ($connection->getDriverName() === 'sqlite' && $connection->getDatabaseName() !== ':memory:') {
                $path = $connection->getDatabaseName();
                if (! is_file($path) && file_put_contents($path, '') === false) {
                    $this->components->error('Could not create the configured audit SQLite file.');

                    return self::FAILURE;
                }
            }
            $status = $this->call('migrate', [
                '--database' => 'audit', '--path' => 'database/migrations/audit',
                '--force' => $this->option('force'), '--no-interaction' => true,
            ]);
            if ($status !== self::SUCCESS) {
                return $status;
            }
            if ($this->option('import-legacy')) {
                $roles = Role::query()->whereIn('name', ['Super Admin', 'Admin', 'Staff'])->pluck('id');
                $administrators = User::query()->whereIn('role_id', $roles)->pluck('id');
                foreach (['admin_activities', 'admin_api_audits'] as $table) {
                    if (! Schema::hasTable($table)) {
                        continue;
                    }
                    $query = DB::table($table)->whereIn('user_id', $administrators);
                    if ($table === 'admin_api_audits') {
                        $query->whereIn('actor_role_id', $roles);
                    }
                    $copied = 0;
                    $query->orderBy('id')->chunkById(250, function (Collection $rows) use ($connection, $table, &$copied): void {
                        foreach ($rows as $row) {
                            $copied += $connection->table($table)->insertOrIgnore((array) $row);
                        }
                    });
                    $this->components->info($table.': '.$copied.' historical administrator records copied.');
                }
            }
            $this->components->info('Administrator audit storage is ready. Legacy application tables were preserved.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error('Audit setup failed ('.$exception::class.'). Check AUDIT_DB_* configuration and database availability.');

            return self::FAILURE;
        }
    }
}
