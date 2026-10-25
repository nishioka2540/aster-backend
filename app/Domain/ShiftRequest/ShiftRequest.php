<?php

namespace App\Domain\ShiftRequest;

use DateTimeImmutable;
use DomainException;

/** 新規シフト依頼。業務上の制約をHTTP・DBから独立して保証する。 */
final readonly class ShiftRequest
{
    public static function assertCanCreate(bool $hasUnconfirmedRequest): void
    {
        if ($hasUnconfirmedRequest) {
            throw new DomainException('未確定のシフトがあるため、新規の提出依頼は作成できません。');
        }
    }

    public function revise(DateTimeImmutable $start, DateTimeImmutable $end, DateTimeImmutable $deadline, ?string $notice): self
    {
        if ($this->status !== ShiftRequestStatus::Unconfirmed) {
            throw new DomainException('確定済みの提出依頼は修正できません。');
        }

        return new self($this->requestedBy, $start, $end, $deadline, $notice, $this->status);
    }

    public function __construct(
        public int $requestedBy,
        public DateTimeImmutable $periodStart,
        public DateTimeImmutable $periodEnd,
        public DateTimeImmutable $submissionDeadline,
        public ?string $notice,
        public ShiftRequestStatus $status = ShiftRequestStatus::Unconfirmed,
    ) {
        if ($requestedBy <= 0) {
            throw new DomainException('依頼者が正しくありません。');
        }
        if ($periodEnd->format('Y-m-d') < $periodStart->format('Y-m-d')) {
            throw new DomainException('終了日は開始日以降にしてください。');
        }
        foreach ([$periodStart, $periodEnd, $submissionDeadline] as $date) {
            if ((int) $date->format('N') >= 6) {
                throw new DomainException('日付には平日を指定してください。');
            }
        }
        if ($notice !== null && mb_strlen($notice) > 5000) {
            throw new DomainException('お知らせは5000文字以内で入力してください。');
        }
    }
}
