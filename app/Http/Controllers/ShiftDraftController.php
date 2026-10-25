<?php

namespace App\Http\Controllers;

use App\Application\Query\SavedShiftQueryService;
use App\Application\Shift\SubmitShiftUseCase;
use App\Http\Requests\SaveShiftDraftRequest;
use DomainException;
use Enum\ShiftStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * シフト下書きの保存と保存済みシフトの一覧取得を受け付ける。
 * 入力検証後、Domain生成・保存はUsecase、一覧取得はQueryServiceへ委譲する。
 */
final class ShiftDraftController extends Controller
{
    public function store(SaveShiftDraftRequest $request, SubmitShiftUseCase $useCase): JsonResponse
    {
        $data = $request->validated();
        try {
            $id = $useCase->execute((int) $data['shift_request_id'], (int) $request->user()->getAuthIdentifier(), $data['days'], ShiftStatus::Draft);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['days' => $exception->getMessage()]);
        }

        return response()->json(['id' => $id, 'message' => '下書きを保存しました。']);
    }

    public function index(Request $request, SavedShiftQueryService $service): JsonResponse
    {
        abort_unless($request->user()->canRegisterShifts(), 403);

        return response()->json(['data' => $service->forUser((int) $request->user()->getAuthIdentifier())]);
    }
}
