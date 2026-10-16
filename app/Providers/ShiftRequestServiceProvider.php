<?php

namespace App\Providers;

use App\Domain\ShiftRequest\ShiftRequestRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentShiftRequestRepository;
use Illuminate\Support\ServiceProvider;

class ShiftRequestServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(\App\Domain\ShiftSchedule\ShiftScheduleRepository::class, \App\Infrastructure\Persistence\Eloquent\EloquentShiftScheduleRepository::class);
        $this->app->bind(\App\Domain\Shift\ShiftStatusRepository::class, \App\Infrastructure\Persistence\Eloquent\EloquentShiftStatusRepository::class);
        $this->app->bind(\App\Domain\Shift\ShiftRepository::class, \App\Infrastructure\Persistence\Eloquent\EloquentShiftRepository::class);
        $this->app->bind(\App\Application\ShiftRequest\ShiftRequestAvailabilityQueryService::class, \App\Infrastructure\Query\SqlShiftRequestAvailabilityQueryService::class);
        $this->app->bind(ShiftRequestRepository::class, EloquentShiftRequestRepository::class);
    }
}
