<?php

namespace Tests\Unit;

use App\Domain\ShiftRequest\ShiftRequest;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

class ShiftRequestTest extends TestCase
{
    public function test_domain_rejects_reversed_period_without_http_validation(): void
    {
        $this->expectException(DomainException::class);
        new ShiftRequest(1, new DateTimeImmutable('2026-10-16'), new DateTimeImmutable('2026-10-12'), new DateTimeImmutable('2026-10-09'), null);
    }

    public function test_domain_rejects_weekend_without_http_validation(): void
    {
        $this->expectException(DomainException::class);
        new ShiftRequest(1, new DateTimeImmutable('2026-10-12'), new DateTimeImmutable('2026-10-16'), new DateTimeImmutable('2026-10-11'), null);
    }
}
