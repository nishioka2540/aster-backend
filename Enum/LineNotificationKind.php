<?php

namespace Enum;

/** シフトに関するLINE通知の種別。 */
enum LineNotificationKind: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Reminder = 'reminder';
    case Confirmed = 'confirmed';

    public function title(): string
    {
        return match ($this) {
            self::Created => 'シフト提出のお願い',
            self::Updated => 'シフト提出依頼が変更されました',
            self::Reminder => 'シフト提出のリマインド',
            self::Confirmed => 'シフトが確定しました',
        };
    }
}
