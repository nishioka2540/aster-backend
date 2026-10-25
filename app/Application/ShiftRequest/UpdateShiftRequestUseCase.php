<?php

namespace App\Application\ShiftRequest;

use App\Domain\ShiftRequest\ShiftRequestRepository;
use DateTimeImmutable;
use DateTimeZone;
use Enum\LineNotificationKind;

/** 依頼をDomainとして復元・修正し、関連するシフトとともに保存する。 */
final readonly class UpdateShiftRequestUseCase
{
    public function __construct(private ShiftRequestRepository $repository, private LineShiftNotifier $notifier) {}

    public function execute(int $id, array $data): string
    {
        return $this->repository->transaction(function () use ($id, $data) {
            $existing = $this->repository->findForUpdate($id);
            abort_unless($existing, 404);
            $timezone = new DateTimeZone('Asia/Tokyo');
            $revised = $existing->revise(
                new DateTimeImmutable($data['period_start'], $timezone),
                new DateTimeImmutable($data['period_end'], $timezone),
                new DateTimeImmutable($data['submission_deadline'], $timezone),
                $data['notice'] ?? null,
            );
            $periodChanged = $existing->periodStart != $revised->periodStart || $existing->periodEnd != $revised->periodEnd;
            $this->repository->update($id, $revised, $periodChanged);

            return $this->notifier->enqueue($id, LineNotificationKind::Updated);
        });
    }
}
