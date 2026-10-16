<?php

namespace App\Application\ShiftRequest;

interface ShiftRequestAvailabilityQueryService
{
    /** @return list<object> */
    public function findAcceptingRequests(): array;

    public function isAccepting(int $requestId): bool;

    public function latestConfirmedEnd(): ?string;
}
