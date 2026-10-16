<?php

namespace App\Domain\Shift;

interface ShiftStatusRepository
{
    /** @return list<ShiftSubmissionPeriod> */
    public function findAcceptingPeriods(): array;

    /** @return array<int, list<CastShiftSubmission>> 依頼IDごとの提出済みキャスト */
    public function findSubmitted(): array;

    /** @return array<int, list<CastShiftSubmission>> 依頼IDごとの未提出キャスト */
    public function findUnsubmitted(): array;
}
