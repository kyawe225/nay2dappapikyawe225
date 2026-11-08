<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Throwable;

class SetupAuditDatabase extends Command
{
    protected $signature = 'audit:setup {--force : Run migrations in production}';

    protected $description = 'Run application migrations, including administrator audit storage';

    public function handle(): int
    {
        try {
            $status = $this->call('migrate', [
                '--force' => $this->option('force'), '--no-interaction' => true,
            ]);
            if ($status !== self::SUCCESS) {
                return $status;
            }
            $this->components->info('Administrator audit storage is ready in the application database.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error('Audit setup failed ('.$exception::class.'). Check application database configuration and availability.');

            return self::FAILURE;
        }
    }
}
