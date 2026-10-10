<?php

namespace App\Http\Requests;

use App\Models\LearningSpace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLearningSpaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'purpose' => ['required', Rule::in(array_keys(LearningSpace::PURPOSES))],
            'goal' => ['nullable', 'string', 'max:500'],
            'subject' => ['nullable', 'string', 'max:120'],
            'level' => ['nullable', 'string', 'max:120'],
            'exam_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
