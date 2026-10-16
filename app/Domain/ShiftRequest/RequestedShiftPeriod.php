<?php

namespace App\Domain\ShiftRequest;

/** 登録受付中のシフト依頼の参照モデル。 */
final readonly class RequestedShiftPeriod
{
    public function __construct(
        public int $id,
        public string $periodStart,
        public string $periodEnd,
        public string $submissionDeadline,
        public ?string $notice,
    ) {}
}
