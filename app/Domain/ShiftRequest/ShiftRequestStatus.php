<?php

namespace App\Domain\ShiftRequest;

enum ShiftRequestStatus: int
{
    case Unconfirmed = 0;
    case Confirmed = 1;
}
