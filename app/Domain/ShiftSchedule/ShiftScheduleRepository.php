<?php
namespace App\Domain\ShiftSchedule;

interface ShiftScheduleRepository
{
    public function transaction(callable $operation): mixed;
    public function find(int $requestId, bool $lock = false): ?ShiftSchedule;
    public function publishedForCast(int $castId): array;
    public function castIds(int $requestId, string $date): array;
    public function allCastIds(): array;
    public function days(int $requestId): array;
    public function assignments(int $requestId, string $date): array;
    public function saveDay(int $requestId, string $date, array $casts, int $adminId, ?int $requiredCount = null): void;
    public function confirm(int $requestId, int $adminId, array $dates, bool $complete): void;
}
