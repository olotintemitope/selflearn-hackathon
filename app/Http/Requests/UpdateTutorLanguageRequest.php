<?php

namespace App\Http\Requests;

use App\Models\LearningSpace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTutorLanguageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $space = $this->route('learningSpace');

        return $this->user() !== null && $space instanceof LearningSpace && $space->user_id === $this->user()->id;
    }

    public function rules(): array
    {
        return [
            'preferred_language' => ['required', 'string', Rule::in(array_keys(LearningSpace::TUTOR_LANGUAGES))],
        ];
    }
}
