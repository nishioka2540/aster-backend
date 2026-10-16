<?php

namespace App\Application\ShiftRequest;

/** HTTPに依存せず、登録ユースケースへ入力を渡す。 */
final readonly class CreateShiftRequestDto
{
    public function __construct(
        public int $requestedBy,
        public string $periodStart,
        public string $periodEnd,
        public string $submissionDeadline,
        public ?string $notice,
    ) {}
}
