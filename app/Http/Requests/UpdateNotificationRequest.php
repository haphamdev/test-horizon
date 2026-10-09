<?php

namespace App\Http\Requests;

use App\Enums\Priority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'message' => ['sometimes', 'required', 'string'],
            'priority' => ['sometimes', 'required', Rule::enum(Priority::class)],
            'recipients' => ['sometimes', 'required', 'array', 'min:1'],
            'recipients.*' => ['required', 'string'],
            'scheduled_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }
}
