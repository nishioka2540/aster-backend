<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReceiveShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManageShifts();
    }

    public function rules(\App\Application\ShiftRequest\ShiftRequestAvailabilityQueryService $availability): array
    {
        $weekday = function (string $attribute, mixed $value, \Closure $fail): void {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) $value, 0, 10));
            if ($date && (int) $date->format('N') >= 6) {
                $fail('日付には平日を指定してください。');
            }
        };

        return [
            'period_start' => ['required', 'date_format:Y-m-d', $weekday, function (string $attribute, mixed $value, \Closure $fail) use ($availability): void {
                $end = $availability->latestConfirmedEnd();
                if ($end !== null && $value <= $end) {
                    $fail('fromは確定済み期間の終了日より後の平日にしてください。');
                }
            }],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start', $weekday],
            'submission_deadline' => ['required', 'date_format:Y-m-d H:i:s', $weekday],
            'notice' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            '*.required' => '日付を入力してください。',
            '*.date_format' => '日付の形式が正しくありません。',
            'period_end.after_or_equal' => 'toはfrom以降の日付にしてください。',
            'notice.max' => 'お知らせは5000文字以内で入力してください。',
        ];
    }
}
