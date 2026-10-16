<?php

namespace App\Application\Shift;

use App\Domain\Shift\Shift;
use App\Domain\Shift\ShiftRepository;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Enum\ShiftStatus;

final readonly class SubmitShiftUseCase
{
    public function __construct(private ShiftRepository $repository) {}

    public function execute(int $requestId, int $userId, array $days, ShiftStatus $status = ShiftStatus::Submitted): int
    {
        return $this->repository->transaction(function () use ($requestId, $userId, $days, $status) {
            $request = $this->repository->findRequestForUpdate($requestId);
            if (! $request) {
                throw new DomainException('シフト依頼が見つかりません。');
            }
            $timezone = new DateTimeZone('Asia/Tokyo');
            $shift = new Shift($requestId, $userId, $days, \Carbon\CarbonImmutable::now('Asia/Tokyo')->toDateTimeImmutable(),
                $request['period_start'], $request['period_end'],
                new DateTimeImmutable($request['submission_deadline'], $timezone), $request['accepting'], $status);

            return $this->repository->save($shift);
        });
    }
}
