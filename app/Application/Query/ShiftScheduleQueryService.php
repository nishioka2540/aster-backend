<?php

namespace App\Application\Query;

use App\Domain\ShiftSchedule\ShiftSchedule;
use App\Domain\ShiftSchedule\ShiftScheduleRepository;
use Carbon\CarbonImmutable;
use DomainException;
use Enum\UserType;
use Illuminate\Support\Facades\DB;

/** 編成状況・日別候補・公開済みシフトを取得する参照Service。 */
final readonly class ShiftScheduleQueryService
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
            'required_count' => $days[$date]['required_count'] ?? 0, 'date' => $date, 'confirmed' => $days[$date]['confirmed'] ?? false, 'count' => $days[$date]['count'] ?? 0, 'finalized' => $days[$date]['finalized'] ?? false,
        ], $schedule->dates())];
    }

    public function assignments(int $id, string $date): array
    {
        $schedule = $this->find($id);
        $schedule->validateDate($date);
        $result = ['required_count' => $this->repository->days($id)[$date]['required_count'] ?? 0, 'confirmed' => $schedule->confirmed || ($this->repository->days($id)[$date]['finalized'] ?? false), 'casts' => $this->repository->assignments($id, $date)];
        $casts = DB::table('mst_users')->whereIn('id', \App\Services\StoreAccess::castIds())->orderBy('name')->get(['id', 'ulid', 'name']);
        $ulids = $casts->pluck('ulid', 'id');
        $result['casts'] = array_map(fn ($cast) => [...$cast, 'id' => $ulids[$cast['id']]], $result['casts']);
        $result['candidates'] = $result['confirmed']
            ? $casts->whereNotIn('ulid', array_column($result['casts'], 'id'))
                ->map(fn ($cast) => ['id' => $cast->ulid, 'name' => $cast->name])->values()->all()
            : [];

        return $result;
    }
}
