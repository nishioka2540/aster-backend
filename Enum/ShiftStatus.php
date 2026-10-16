<?php

namespace Enum;

enum ShiftStatus: int
{
    case NotStarted = 0;
    case Draft = 1;
    case Submitted = 2;

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => '未着手',
            self::Draft => '下書き',
            self::Submitted => '提出済み',
        };
    }
}
