<form class="tutor-language-form" method="POST" action="{{ route('tutor.language.update', $learningSpace) }}" aria-labelledby="tutor-language-heading">
    @csrf
    <h3 id="tutor-language-heading">Tutor reply language</h3>
    <p id="tutor-language-help" class="muted">Applies to new tutor replies in this study space. Source quotations stay in their original wording. Translation quality may vary.</p>
    <label for="tutor-language">Choose a language
        <select id="tutor-language" name="preferred_language" aria-describedby="tutor-language-help{{ $errors->has('preferred_language') ? ' tutor-language-error' : '' }}" @if ($errors->has('preferred_language')) aria-invalid="true" @endif required>
            @foreach (\App\Models\LearningSpace::TUTOR_LANGUAGES as $code => $label)
                <option value="{{ $code }}" @selected($learningSpace->preferred_language === $code)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    @error('preferred_language')<p id="tutor-language-error" class="field-error">{{ $message }}</p>@enderror
    <button type="submit">Save tutor language</button>
</form>
