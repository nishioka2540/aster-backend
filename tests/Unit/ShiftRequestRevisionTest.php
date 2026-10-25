<?php

namespace Tests\Unit;

use App\Domain\ShiftRequest\ShiftRequest;
use App\Domain\ShiftRequest\ShiftRequestStatus;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

class ShiftRequestRevisionTest extends TestCase
{
    private function request(ShiftRequestStatus $status = ShiftRequestStatus::Unconfirmed): ShiftRequest
    {
        return new ShiftRequest(7, new DateTimeImmutable('2026-10-19'), new DateTimeImmutable('2026-10-23'),
            new DateTimeImmutable('2026-10-16 23:59:59'), 'Original', $status);
    }

    public function test_revision_creates_a_validated_domain_preserving_requester_and_original(): void
    {
        $original = $this->request();
        $revised = $original->revise(new DateTimeImmutable('2026-10-26'), new DateTimeImmutable('2026-10-30'),
            new DateTimeImmutable('2026-10-23 23:59:59'), 'Changed');

        $this->assertNotSame($original, $revised);
        $this->assertSame(7, $revised->requestedBy);
        $this->assertSame(ShiftRequestStatus::Unconfirmed, $revised->status);
        $this->assertSame('2026-10-26', $revised->periodStart->format('Y-m-d'));
        $this->assertSame('Changed', $revised->notice);
        $this->assertSame('2026-10-19', $original->periodStart->format('Y-m-d'));
        $this->assertSame('Original', $original->notice);
    }

    public function test_confirmed_request_cannot_be_revised_without_http_validation(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('確定済みの提出依頼は修正できません。');
        $this->request(ShiftRequestStatus::Confirmed)->revise(new DateTimeImmutable('2026-10-26'),
            new DateTimeImmutable('2026-10-30'), new DateTimeImmutable('2026-10-23'), null);
    }

    public function test_revision_rejects_reversed_period_without_http_validation(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('終了日は開始日以降にしてください。');
        $this->request()->revise(new DateTimeImmutable('2026-10-30'), new DateTimeImmutable('2026-10-26'),
            new DateTimeImmutable('2026-10-23'), null);
    }

    public function test_revision_rejects_weekend_without_http_validation(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('日付には平日を指定してください。');
        $this->request()->revise(new DateTimeImmutable('2026-10-24'), new DateTimeImmutable('2026-10-30'),
            new DateTimeImmutable('2026-10-23'), null);
    }
}
