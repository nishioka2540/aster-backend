<?php

namespace App\Http\Requests;

class SaveShiftDraftRequest extends SubmitShiftRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'days' => ['present', 'array']];
    }
}
