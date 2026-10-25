<?php

namespace App\Http\Controllers;

use App\Application\ShiftRequest\SendLineReminderUseCase;
use Illuminate\Http\Request;

/**
 * 管理者のシフト再通知リクエストを認可し、通知Usecaseを呼び出す。
 */
final class LineReminderController extends Controller
{
    public function __invoke(Request $request, int $requestId, SendLineReminderUseCase $useCase)
    {
        abort_unless($request->user()->canManageShifts(), 403);

        return response()->json(['message' => $useCase->execute($requestId)]);
    }
}
