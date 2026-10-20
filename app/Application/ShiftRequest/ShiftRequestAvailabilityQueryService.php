<?php

namespace App\Application\ShiftRequest;

interface ShiftRequestAvailabilityQueryService
{
    /** @return list<object> */
    public function findAcceptingRequests(?int $userId = null): array;

    public function isAccepting(int $requestId, ?int $userId = null): bool;

    public function latestConfirmedEnd(): ?string;
}
