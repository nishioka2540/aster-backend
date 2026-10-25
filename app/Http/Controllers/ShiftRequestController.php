<?php

namespace App\Http\Controllers;

use App\Application\ShiftRequest\CreateShiftRequestDto;
use App\Application\ShiftRequest\CreateShiftRequestUseCase;
use App\Application\ShiftRequest\UpdateShiftRequestUseCase;
use App\Http\Requests\ReceiveShiftRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * シフト提出依頼の登録・修正リクエストを受け付ける。
 * 入力検証後にUsecaseを呼び出し、Domain生成・検証と永続化・通知を委譲する。
 */
class ShiftRequestController extends Controller
{
    public function __invoke(ReceiveShiftRequest $request, CreateShiftRequestUseCase $useCase): JsonResponse
    {
        $data = $request->validated();
        try {
            [$id, $notification] = $useCase->executeWithNotification(new CreateShiftRequestDto(
                requestedBy: (int) $request->user()->getAuthIdentifier(),
                periodStart: $data['period_start'],
                periodEnd: $data['period_end'],
                submissionDeadline: $data['submission_deadline'],
                notice: $data['notice'] ?? null,
            ));
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages(['request' => $exception->getMessage()]);
        }

        return response()->json([
            'id' => $id,
            'message' => 'シフト提出依頼を登録しました。'.$notification,
        ], 201);
    }

    public function update(ReceiveShiftRequest $request, int $requestId, UpdateShiftRequestUseCase $useCase): JsonResponse
    {
        $data = $request->validated();
        try {
            $notification = $useCase->execute($requestId, $data);
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages(['request' => $exception->getMessage()]);
        }

        return response()->json(['id' => $requestId, 'message' => '依頼内容を修正しました。'.$notification]);
    }
}
