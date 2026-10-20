<?php
namespace App\Application\ShiftSchedule;

use App\Domain\ShiftSchedule\ShiftSchedule;
use App\Domain\ShiftSchedule\ShiftScheduleRepository;
use DomainException;
use Carbon\CarbonImmutable;

final readonly class ShiftScheduleUseCase
{
    public function __construct(private ShiftScheduleRepository $repository) {}

    private function find(int $id, bool $lock = false): ShiftSchedule
    {
        return $this->repository->find($id, $lock) ?? throw new DomainException('提出依頼が見つかりません。');
    }

    public function publishedForCast(int $castId): array
    {
        return $this->repository->publishedForCast($castId);
    }

    public function review(int $id): array
    {
        $schedule = $this->find($id);
        $days = $this->repository->days($id);
        return ['confirmed' => $schedule->confirmed, 'confirmation_available' => $schedule->confirmationAvailable(CarbonImmutable::today('Asia/Tokyo')->format('Y-m-d')), 'days' => array_map(fn ($date) => [
            'date' => $date, 'confirmed' => $days[$date]['confirmed'] ?? false, 'count' => $days[$date]['count'] ?? 0, 'finalized' => $days[$date]['finalized'] ?? false,
        ], $schedule->dates())];
    }

    public function assignments(int $id, string $date): array
    {
        $schedule = $this->find($id);
        $schedule->validateDate($date);
        return ['confirmed' => $schedule->confirmed || ($this->repository->days($id)[$date]['finalized'] ?? false), 'casts' => $this->repository->assignments($id, $date)];
    }

    public function decide(int $id, string $date, array $casts, int $adminId): void
    {
        $this->repository->transaction(function () use ($id, $date, $casts, $adminId) {
            $schedule = $this->find($id, true);
            $schedule->decide($date, $casts, $this->repository->castIds($id, $date));
            $this->repository->saveDay($id, $date, $casts, $adminId);
        });
    }

    public function confirm(int $id, int $adminId, ?string $from = null, ?string $to = null): void
    {
        $this->repository->transaction(function () use ($id, $adminId, $from, $to) {
            $schedule = $this->find($id, true);
            $days = $this->repository->days($id);
            $targets = $schedule->confirmationDates($from, $to);
            $schedule->confirm(array_keys(array_filter($days, fn ($day) => $day['confirmed'])), CarbonImmutable::today('Asia/Tokyo')->format('Y-m-d'), $targets);
            // Revalidate cast membership and times in case data changed after a day was decided.
            foreach ($targets as $date) {
                $schedule->decide($date, $this->repository->assignments($id, $date), $this->repository->castIds($id, $date));
            }
            $finalized = array_keys(array_filter($days, fn ($day) => $day['finalized'] ?? false));
            $complete = !array_diff($schedule->dates(), [...$finalized, ...$targets]);
            $this->repository->confirm($id, $adminId, $targets, $complete);
        });
    }
}
