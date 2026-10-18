<?php

namespace App\Domain\Shift;

use DateTimeImmutable;
use DomainException;
use Enum\ShiftStatus;

/** 依頼に対する一人分の提出シフト集約。 */
final readonly class Shift
{
    public function __construct(
        public int $requestId,
        public int $userId,
        public array $days,
        public DateTimeImmutable $submittedAt,
        string $periodStart,
        string $periodEnd,
        DateTimeImmutable $deadline,
        bool $accepting,
        public ShiftStatus $status = ShiftStatus::Submitted,
        array $finalizedDates = [],
    ) {
        if (! $accepting || $submittedAt->format('Y-m-d') > $deadline->format('Y-m-d')) {
            throw new DomainException('この依頼は受付を終了しています。');
        }
        $expected = [];
        for ($date = new DateTimeImmutable($periodStart); $date->format('Y-m-d') <= $periodEnd; $date = $date->modify('+1 day')) {
            if ((int) $date->format('N') < 6 && !in_array($date->format('Y-m-d'), $finalizedDates, true)) {
                $expected[] = $date->format('Y-m-d');
            }
        }
        if (array_intersect(array_column($days, 'work_date'), $finalizedDates)) {
            throw new DomainException('確定済みの日は変更できません。画面を読み込み直してください。');
        }
        if (!$expected) throw new DomainException('変更できる日がありません。');
        $actual = array_column($days, 'work_date');
        sort($actual);
        if (($status === ShiftStatus::Submitted && $actual !== $expected)
            || count($actual) !== count(array_unique($actual)) || array_diff($actual, $expected)) {
            throw new DomainException('対象期間のすべての平日に出勤・休みを指定してください。');
        }
        foreach ($days as $day) {
            if (mb_strlen($day['note'] ?? '') > 1000) {
                throw new DomainException('備考は1000文字以内で入力してください。');
            }
            if (! $day['work']) {
                continue;
            }
            if ($status === ShiftStatus::Draft) {
                $start = isset($day['start_time']) ? self::minutes($day['start_time']) : null;
                $end = isset($day['end_time']) ? self::minutes($day['end_time']) : null;
                if ($end === 0) $end = 1440;
                if (($start !== null && ($start < 1200 || $start > 1320))
                    || ($end !== null && ($end < 1320 || $end > 1440))
                    || ($start !== null && $end !== null && $end <= $start)) {
                    throw new DomainException('出退勤時刻を確認してください。');
                }
                continue;
            }
            $start = self::minutes($day['start_time'] ?? '');
            $end = self::minutes($day['end_time'] ?? '');
            if ($end === 0) {
                $end = 1440;
            }
            if ($start < 1200 || $start > 1320 || $end < 1320 || $end > 1440 || $end <= $start) {
                throw new DomainException('開始は20:00〜22:00、終了は22:00〜翌00:00で、開始より後にしてください。');
            }
        }
    }

    private static function minutes(string $time): int
    {
        if (! preg_match('/^(?:[01][0-9]|2[0-3]):(?:00|15|30|45)$/D', $time)) {
            throw new DomainException('出退勤時刻は15分刻みで入力してください。');
        }

        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }
}
