<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\NotePhase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TradeNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body'        => ['required', 'string'],
            'phase'       => ['required', Rule::in(array_column(NotePhase::cases(), 'value'))],
            'occurred_at' => ['required', 'date'],
        ];
    }
}
