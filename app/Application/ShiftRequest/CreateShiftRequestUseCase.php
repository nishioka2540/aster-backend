<?php

namespace App\Application\ShiftRequest;

use App\Domain\ShiftRequest\ShiftRequest;
use App\Domain\ShiftRequest\ShiftRequestRepository;
use DateTimeImmutable;
use DateTimeZone;

final readonly class CreateShiftRequestUseCase
{
    public function __construct(private ShiftRequestRepository $repository) {}

    public function execute(CreateShiftRequestDto $dto): int
    {
        // 入力は日本の店舗時刻として解釈する。PHPの実行環境のタイムゾーンに依存しない。
        $timezone = new DateTimeZone('Asia/Tokyo');
        $shiftRequest = new ShiftRequest(
            $dto->requestedBy,
            new DateTimeImmutable($dto->periodStart, $timezone),
            new DateTimeImmutable($dto->periodEnd, $timezone),
            new DateTimeImmutable($dto->submissionDeadline, $timezone),
            $dto->notice,
        );

        return $this->repository->save($shiftRequest);
    }
}
