<?php

namespace App\Domain\Shift;

use Enum\ShiftStatus;

final readonly class CastShiftSubmission
{
    public function __construct(
        public int $userId,
        public string $name,
        public ShiftStatus $status,
    ) {}

    public function isSubmitted(): bool
    {
        return $this->status === ShiftStatus::Submitted;
    }
}
