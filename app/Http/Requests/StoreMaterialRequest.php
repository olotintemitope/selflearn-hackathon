<?php

namespace App\Http\Requests;

use App\Models\LearningSpace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreMaterialRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return LearningSpace::whereKey($this->integer('learning_space_id'))
            ->where('user_id', $this->user()?->id)->exists();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'learning_space_id' => ['required', 'integer', 'exists:learning_spaces,id'],
            'title' => ['required', 'string', 'max:150'],
            'material' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ];
    }
}
