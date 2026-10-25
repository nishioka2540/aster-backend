<?php

namespace App\Http\Controllers;

use App\Application\Query\AdminCastShiftQueryService;
use App\Application\Shift\AdminSubmitShiftUseCase;
use App\Http\Requests\AdminSubmitShiftRequest;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 管理者によるキャストのシフト参照・代理保存を受け付ける。
 * 認可・入力検証後、参照はQueryService、Domain生成・保存はUsecaseへ委譲する。
 */
final class AdminCastShiftController extends Controller
{
    public function show(Request $request, int $requestId, string $castId, AdminCastShiftQueryService $service): JsonResponse
    {
        abort_unless($request->user()->canManageShifts(), 403);

        return response()->json($service->find($requestId, $castId));
    }

    public function update(AdminSubmitShiftRequest $request, int $requestId, string $castId, AdminSubmitShiftUseCase $useCase): JsonResponse
    {
        $data = $request->validated();
        abort_unless((int) $data['shift_request_id'] === $requestId, 422);
        try {
            $id = $useCase->execute($requestId, $castId, $data['days']);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['days' => $exception->getMessage()]);
        }

        return response()->json(['id' => $id, 'message' => 'シフトを保存しました。']);
    }
}
