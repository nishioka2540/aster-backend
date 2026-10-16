<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->auth_type === 0;
    }

    public function rules(): array
    {
        return [
            'shift_request_id' => ['required', 'integer', 'min:1'],
            'days' => ['required', 'array', 'min:1'],
            'days.*.work_date' => ['required', 'date_format:Y-m-d', 'distinct'],
            'days.*.work' => ['required', 'boolean'],
            'days.*.start_time' => ['nullable', 'date_format:H:i'],
            'days.*.end_time' => ['nullable', 'date_format:H:i'],
            'days.*.has_reservation' => ['required', 'boolean'],
            'days.*.note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
