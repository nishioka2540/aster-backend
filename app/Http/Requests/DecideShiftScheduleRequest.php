<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
final class DecideShiftScheduleRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->canManageShifts(); }
    public function rules(): array
    {
        return ['required_count' => ['sometimes', 'integer', 'min:0', 'max:65535'], 'casts' => ['present', 'array'], 'casts.*.id' => ['required', 'ulid', 'distinct'],
            'casts.*.work' => ['required', 'boolean'], 'casts.*.start' => ['present', 'string', 'max:5'],
            'casts.*.end' => ['present', 'string', 'max:5'], 'casts.*.reserved' => ['required', 'boolean'],
            'casts.*.note' => ['nullable', 'string', 'max:1000']];
    }
}
