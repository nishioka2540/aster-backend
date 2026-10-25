<?php

namespace Enum;

/** 日別シフトの編成状態。提出状態・依頼状態とは別に扱う。 */
enum ShiftScheduleDayStatus: int
{
    case NotDecided = 0;
    case Decided = 1;
    case Finalized = 2;
}
