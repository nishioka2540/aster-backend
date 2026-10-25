<?php

namespace App\Http\Controllers;

use App\Application\Shift\SubmitShiftUseCase;
use App\Http\Requests\SubmitShiftRequest;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * キャスト本人の勤務希望を受け取り、入力を検証する。
 * シフトDomainの生成・業務検証・永続化をUsecaseへ委譲する。
 */
class SubmitShiftController extends Controller
{
    public function __invoke(SubmitShiftRequest $request, SubmitShiftUseCase $useCase): JsonResponse
    {
        $data = $request->validated();
        try {
            $id = $useCase->execute((int) $data['shift_request_id'], (int) $request->user()->getAuthIdentifier(), $data['days']);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['days' => $exception->getMessage()]);
        }

        return response()->json(['id' => $id, 'message' => 'シフトを登録しました。']);
    }
}
