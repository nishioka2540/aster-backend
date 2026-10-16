<?php

namespace App\Http\Requests;

class AdminSubmitShiftRequest extends SubmitShiftRequest
{
    public function authorize(): bool
    {
        return $this->user()?->auth_type === 1;
    }
}
