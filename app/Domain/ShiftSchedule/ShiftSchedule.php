<?php
namespace App\Domain\ShiftSchedule;

use DateTimeImmutable;
use DomainException;

final readonly class ShiftSchedule
{
    public function __construct(public int $requestId, public string $from, public string $to, public bool $confirmed, public string $submissionDeadline) {}

    public function confirmationAvailable(string $today): bool
    {
        return $today > substr($this->submissionDeadline, 0, 10);
    }

    public function dates(): array
    {
        $dates = [];
        for ($day = new DateTimeImmutable($this->from); $day->format('Y-m-d') <= $this->to; $day = $day->modify('+1 day')) {
            if ((int) $day->format('N') < 6) $dates[] = $day->format('Y-m-d');
        }
        return $dates;
    }

    public function validateDate(string $date): void
    {
        if (!in_array($date, $this->dates(), true)) throw new DomainException('対象期間内の平日を指定してください。');
    }

    public function decide(string $date, array $casts, array $castIds, array $additionalIds = []): void
    {
        $this->validateDate($date);
        $ids = array_map(fn ($cast) => (int) $cast['id'], $casts);
        sort($ids);
        sort($castIds);
        if (count($ids) !== count(array_unique($ids)) || array_diff($castIds, $ids)
            || array_diff($ids, [...$castIds, ...$additionalIds])) {
            throw new DomainException('対象キャストが変更されています。画面を読み込み直してください。');
        }
        foreach ($casts as $cast) {
            if (mb_strlen($cast['note'] ?? '') > 1000) throw new DomainException('備考は1000文字以内にしてください。');
            if (!$cast['work']) continue;
            $start = self::minutes($cast['start']);
            $end = $cast['end'] === '24:00' ? 1440 : self::minutes($cast['end']);
            if ($start < 1200 || $start > 1320 || $end < 1320 || $end > 1440 || $end <= $start) {
                throw new DomainException('開始20:00〜22:00、終了22:00〜24:00で指定してください。');
            }
        }
    }

    public function confirmationDates(?string $from, ?string $to): array
    {
        $from ??= $this->from;
        $to ??= $this->to;
        if ($from < $this->from || $to > $this->to || $from > $to) {
            throw new DomainException('依頼期間内の確定範囲を指定してください。');
        }
        $dates = array_values(array_filter($this->dates(), fn ($date) => $date >= $from && $date <= $to));
        if (!$dates) throw new DomainException('確定範囲に平日がありません。');
        return $dates;
    }

    public function confirm(array $decidedDates, string $today, ?array $targetDates = null): void
    {
        if (!$this->confirmationAvailable($today)) throw new DomainException('提出期限内はシフトを確定できません。提出期限の翌日から確定できます。');
        if ($this->confirmed) throw new DomainException('この期間はすでに確定済みです。');
        if (!($targetDates ?? $this->dates()) || array_diff($targetDates ?? $this->dates(), $decidedDates)) throw new DomainException('期間内のすべての平日を決定してください。');
    }

    private static function minutes(string $time): int
    {
        if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $time)) throw new DomainException('勤務時間はHH:mm形式で指定してください。');
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }
}
