<?php

namespace App\Application\ShiftSchedule;

use App\Domain\ShiftSchedule\ShiftSchedule;
use App\Domain\ShiftSchedule\ShiftScheduleRepository;
use Carbon\CarbonImmutable;
use DomainException;
use Enum\LineNotificationKind;
use Enum\UserType;

/** シフト編成Domainの復元・業務検証を行い、決定・確定結果を保存する。 */
final readonly class ShiftScheduleUseCase
{
    public function __construct(private ShiftScheduleRepository $repository, private \App\Application\ShiftRequest\LineShiftNotifier $notifier) {}

    private function find(int $id, bool $lock = false): ShiftSchedule
    {
        return $this->repository->find($id, $lock) ?? throw new DomainException('提出依頼が見つかりません。');
    }

    public function decideForCasts(int $id, string $date, array $casts, int $adminId): void
    {
        $casts = array_map(function ($cast) {
            $user = \App\Models\User::whereIn('id', \App\Services\StoreAccess::castIds())->where('ulid', $cast['id'])->first();
            if (! $user) {
                throw \Illuminate\Validation\ValidationException::withMessages(['casts' => 'キャストが見つかりません。']);
            }

            return [...$cast, 'id' => $user->id];
        }, $casts);
        $this->decide($id, $date, $casts, $adminId);
    }

    public function confirmWithNotification(int $id, int $adminId, ?string $from, ?string $to, bool $notifyLine): string
    {
        return $this->repository->transaction(function () use ($id, $adminId, $from, $to, $notifyLine) {
            $schedule = $this->repository->find($id, true);
            abort_unless($schedule, 404);
            $targets = $schedule->confirmationDates($from, $to);
            $days = $this->repository->days($id);
            $newDates = array_values(array_filter($targets, fn ($date) => ($days[$date]['confirmed'] ?? false) && ! ($days[$date]['finalized'] ?? false)));
            sort($newDates);
            $this->confirm($id, $adminId, $from, $to);
            $message = '期間のシフトを確定しました。';
            if ($notifyLine && $newDates !== []) {
                $message .= $this->notifier->enqueue($id, LineNotificationKind::Confirmed, $newDates[0], $newDates[count($newDates) - 1]);
            }

            return $message;
        });
    }

    public function decide(int $id, string $date, array $casts, int $adminId): void
    {
        $this->repository->transaction(function () use ($id, $date, $casts, $adminId) {
            $schedule = $this->find($id, true);
            $finalized = $this->repository->days($id)[$date]['finalized'] ?? false;
            $schedule->decide($date, $casts, $this->repository->castIds($id, $date),
                $finalized ? $this->repository->allCastIds() : []);
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
            $complete = ! array_diff($schedule->dates(), [...$finalized, ...$targets]);
            $this->repository->confirm($id, $adminId, $targets, $complete);
        });
    }
}
