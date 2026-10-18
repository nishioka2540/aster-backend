<?php

namespace App\Http\Controllers;

use App\Application\ShiftRequest\CreateShiftRequestDto;
use App\Application\ShiftRequest\CreateShiftRequestUseCase;
use App\Http\Requests\ReceiveShiftRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 対象期間・提出期限・お知らせを指定したシフト提出依頼の登録を受け付ける。
 */
class ShiftRequestController extends Controller
{
    public function __invoke(ReceiveShiftRequest $request, CreateShiftRequestUseCase $useCase): JsonResponse
    {
        $data = $request->validated();
        try {
            $result = DB::transaction(function () use ($useCase, $request, $data) {
                $id = $useCase->execute(new CreateShiftRequestDto(
                    requestedBy: (int) $request->user()->getAuthIdentifier(),
                    periodStart: $data['period_start'],
                    periodEnd: $data['period_end'],
                    submissionDeadline: $data['submission_deadline'],
                    notice: $data['notice'] ?? null,
                ));

                return [$id, app(\App\Application\ShiftRequest\LineShiftNotifier::class)->enqueue($id, 'created')];
            });
            [$id, $notification] = $result;
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages(['request' => $exception->getMessage()]);
        }

        return response()->json([
            'id' => $id,
            'message' => 'シフト提出依頼を登録しました。'.$notification,
        ], 201);
    }

    public function update(ReceiveShiftRequest $request, int $requestId): JsonResponse
    {
        $data = $request->validated();
        $notification = DB::transaction(function () use ($data, $requestId) {
            $existing = DB::table('t_shift_requests')->where('id', $requestId)
                ->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($existing, 404);
            if ((int) $existing->status !== 0) {
                throw ValidationException::withMessages(['request' => '確定済みの提出依頼は修正できません。']);
            }
            $now = now();
            $periodChanged = $existing->period_start !== $data['period_start'] || $existing->period_end !== $data['period_end'];
            if ($periodChanged) {
                // Keep the cast's inputs, but require resubmission for the changed period.
                DB::table('t_shifts')->where('shift_request_id', $requestId)->whereNull('deleted_at')
                    ->update(['status' => 1, 'submitted_at' => null, 'updated_at' => $now]);
                $dayIds = DB::table('t_shift_schedule_days')->where('shift_request_id', $requestId)->pluck('id');
                DB::table('t_shift_assignments')->whereIn('shift_schedule_day_id', $dayIds)
                    ->update(['deleted_at' => $now, 'updated_at' => $now]);
                DB::table('t_shift_schedule_days')->where('shift_request_id', $requestId)
                    ->update(['status' => 0, 'decided_by' => null, 'decided_at' => null,
                        'confirmed_by' => null, 'confirmed_at' => null, 'deleted_at' => $now, 'updated_at' => $now]);
            }
            DB::table('t_shift_requests')->where('id', $requestId)->update([
                'period_start' => $data['period_start'], 'period_end' => $data['period_end'],
                'submission_deadline' => $data['submission_deadline'], 'notice' => $data['notice'] ?? null,
                'updated_at' => $now,
            ]);

            return app(\App\Application\ShiftRequest\LineShiftNotifier::class)->enqueue($requestId, 'updated');
        });

        return response()->json(['id' => $requestId, 'message' => '依頼内容を修正しました。'.$notification]);
    }
}
