<?php

namespace App\Domain\Shift;

/** 受付中の依頼に対するキャストの提出状況。 */
final readonly class ShiftSubmissionPeriod
{
    /** @param list<CastShiftSubmission> $casts */
    public function __construct(
        public int $requestId,
        public string $periodStart,
        public string $periodEnd,
        public array $casts,
    ) {}

    /** @return list<CastShiftSubmission> */
    public function submitted(): array
    {
        return array_values(array_filter($this->casts, fn (CastShiftSubmission $cast) => $cast->isSubmitted()));
    }

    /** @return list<CastShiftSubmission> */
    public function unsubmitted(): array
    {
        return array_values(array_filter($this->casts, fn (CastShiftSubmission $cast) => ! $cast->isSubmitted()));
    }
}
