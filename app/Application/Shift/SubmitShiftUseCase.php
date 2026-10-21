<?php

namespace App\Application\Shift;

use App\Domain\Shift\Shift;
use App\Domain\Shift\ShiftRepository;
use DomainException;
use Enum\ShiftStatus;

final readonly class SubmitShiftUseCase
{
    public function __construct(private ShiftRepository $repository) {}

    public function execute(int $requestId, int $userId, array $days, ShiftStatus $status = ShiftStatus::Submitted, bool $byAdmin = false): int
    {
        return $this->repository->transaction(function () use ($requestId, $userId, $days, $status, $byAdmin) {
            $request = $this->repository->findRequestForUpdate($requestId, $userId);
            if (! $request) {
                throw new DomainException('シフト依頼が見つかりません。');
            }
            $shift = new Shift($requestId, $userId, $days, \Carbon\CarbonImmutable::now('Asia/Tokyo')->toDateTimeImmutable(),
                $request['period_start'], $request['period_end'],
                $byAdmin ? $request['open'] : $request['accepting'], $status, $request['finalized_dates'] ?? []);

            return $this->repository->save($shift);
        });
    }
}
