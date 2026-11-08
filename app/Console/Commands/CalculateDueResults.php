<?php

namespace App\Console\Commands;

use App\Http\ServiceContractor\IBetService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('betting:calculate-due-results')]
#[Description('Calculate recorded results whose automatic calculation time has arrived')]
class CalculateDueResults extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(IBetService $service): int
    {
        $count = $service->CalculateDueResults();
        $this->components->info('Processed '.$count.' due result(s).');

        return self::SUCCESS;
    }
}
