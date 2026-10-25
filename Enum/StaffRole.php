<?php

namespace Enum;

/** 黒服の役職。キャストの役職とは別に扱う。 */
enum StaffRole: int
{
    case StoreManager = 1;
    case Manager = 2;
    case Chief = 3;
    case Leader = 4;
    case Other = 5;
}
