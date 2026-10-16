<?php

namespace App\Application\Shift;

use App\Domain\Shift\ShiftStatusRepository;
use App\Domain\Shift\ShiftSubmissionPeriod;

final readonly class GetShiftStatusUseCase
{
    public function __construct(private ShiftStatusRepository $repository) {}

    /** @return list<ShiftSubmissionPeriod> */
    public function execute(): array
    {
        return $this->repository->findAcceptingPeriods();
    }
}
