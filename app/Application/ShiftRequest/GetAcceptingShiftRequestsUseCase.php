<?php
namespace App\Application\ShiftRequest;
use App\Domain\ShiftRequest\ShiftRequestRepository;
final readonly class GetAcceptingShiftRequestsUseCase
{
    public function __construct(private ShiftRequestRepository $repository) {}
    /** @return list<\App\Domain\ShiftRequest\RequestedShiftPeriod> */
    public function execute(): array { return $this->repository->findAcceptingRequests(); }
}
