<?php

namespace App\Domain\ShiftRequest;

interface ShiftRequestRepository
{
    /** @return list<RequestedShiftPeriod> */
    public function findAcceptingRequests(?int $userId = null): array;

    /** 保存した依頼のIDを返す。 */
    public function save(ShiftRequest $shiftRequest): int;
}
