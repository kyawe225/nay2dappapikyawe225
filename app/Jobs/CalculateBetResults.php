<?php

namespace App\Jobs;

use App\Http\ServiceContractor\IBetService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class CalculateBetResults implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public bool $force = false;

    public function __construct(public string $game, public string $drawDate, public ?string $timeType, public string $revision, bool $force = false)
    {
        $this->force = $force;
    }

    public function handle(IBetService $service): void
    {
        $service->CalculateResults($this->game, $this->drawDate, $this->timeType, $this->revision, $this->force);
    }

    public function failed(?Throwable $exception): void
    {
        app(IBetService::class)->CalculationFailed($this->game, $this->drawDate, $this->timeType, $this->revision);
    }
}
