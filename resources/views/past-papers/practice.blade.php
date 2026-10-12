<x-layouts.app :title="'Past-paper practice | SelfLearn'">
    <div class="stack past-paper-practice">
        <a href="{{ route('materials.show', $material) }}#past-paper-questions-heading">Back to {{ $material->title }}</a>
        <header>
            <p class="eyebrow">{{ $material->title }} · Past-paper practice</p>
            <h1>Question {{ $question->question_number }}</h1>
            <p id="past-paper-practice-instructions" class="lede">Source page {{ $question->page ?? 'not specified' }}. This is a detected question from your uploaded paper, not a complete exam simulation.</p>
            <x-read-aloud target="past-paper-practice-instructions" language="en" />
            @if ($question->paper_year)
                <p class="muted">Year {{ $question->paper_year }} from the upload label.</p>
            @endif
            @if ($question->marks)
                <p class="muted">{{ $question->marks }} {{ Illuminate\Support\Str::plural('mark', $question->marks) }} detected.</p>
            @endif
        </header>

        @if ($attempt)
            <section class="quiz-results" role="status" aria-live="polite">
                <p class="eyebrow">Response submitted</p>
                @if ($attempt->outcome === 'correct')
                    <h2>Your answer matches the detected source response.</h2>
                    <p>This is a comparison with text detected in the uploaded paper, not an independently verified official mark.</p>
                @elseif ($attempt->outcome === 'incorrect')
                    <h2>Your answer does not match the detected source response.</h2>
                    <p>This comparison may be affected by OCR errors. Check the original paper and any supplied marking scheme.</p>
                @else
                    <h2>Your response is saved, but no score was assigned.</h2>
                    <p>No unambiguous answer key was available for this format. We have not generated or guessed one.</p>
                @endif
                @if ($question->answer_source === 'source_expected_response' && $question->expected_answer)
                    <div class="answer-feedback">
                        <strong>Detected source response</strong>
                        <p data-render-math>{{ $question->expected_answer }}</p>
                        <p class="muted">Basis: text labelled as an expected response in the extracted source. It has not been verified against a separate marking scheme.</p>
                    </div>
                @else
                    <div class="answer-feedback"><strong>No answer key detected.</strong><p>Use the original paper or add a matching marking scheme before treating an answer as correct.</p></div>
                @endif
            </section>
        @else
            @if ($question->review_reason)
                <p class="notice" role="status">{{ $question->review_reason }}</p>
            @endif
            <p>Submit your response to see whether a clear answer can be checked. If the paper has no unambiguous key for this question, your response will be saved without a score.</p>
        @endif

        <section class="quiz-question-surface card" aria-labelledby="past-paper-prompt">
            <form method="POST" action="{{ route('past-paper-questions.submit', [$material, $question]) }}" class="quiz-form">
                @csrf
                <fieldset id="past-paper-question-content" class="quiz-question">
                    <legend id="past-paper-prompt"><span class="eyebrow">{{ ucfirst(str_replace('_', ' ', $question->question_type)) }}</span><span class="quiz-prompt" data-render-math>{{ $question->prompt }}</span></legend>
                    <p class="quiz-source-context"><span>Source:</span> {{ $material->title }}
                        @if ($question->paper_year)
                            · year {{ $question->paper_year }} from upload label
                        @endif
                        · page {{ $question->page ?? 'not specified' }} · question {{ $question->question_number }}
                        @if ($question->marks)
                            · {{ $question->marks }} {{ Illuminate\Support\Str::plural('mark', $question->marks) }} detected
                        @endif
                    </p>

                    @if (in_array($question->question_type, ['single_choice', 'true_false'], true))
                        <div class="quiz-options">
                            @foreach ($question->options ?? [] as $option)
                                <label class="quiz-option" for="past-paper-option-{{ $option['id'] }}">
                                    <input id="past-paper-option-{{ $option['id'] }}" type="radio" name="value" value="{{ $option['id'] }}" @checked(old('value') === $option['id']) @disabled($attempt) @if (! $attempt) required @endif>
                                    <span class="option-id">{{ $option['id'] }}</span><span data-render-math>{{ $option['text'] }}</span>
                                </label>
                            @endforeach
                        </div>
                    @elseif ($question->question_type === 'multiple_select')
                        <div class="quiz-options">
                            @foreach ($question->options ?? [] as $option)
                                <label class="quiz-option" for="past-paper-option-{{ $option['id'] }}">
                                    <input id="past-paper-option-{{ $option['id'] }}" type="checkbox" name="values[]" value="{{ $option['id'] }}" @checked(in_array($option['id'], old('values', []), true)) @disabled($attempt)>
                                    <span class="option-id">{{ $option['id'] }}</span><span data-render-math>{{ $option['text'] }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('values')<p class="error" role="alert">{{ $message }}</p>@enderror
                        @error('values.*')<p class="error" role="alert">{{ $message }}</p>@enderror
                    @elseif ($question->question_type === 'fill_gap')
                        <label for="past-paper-response">Your answer</label>
                        <input id="past-paper-response" type="text" name="value" maxlength="8000" value="{{ $attempt?->response['value'] ?? old('value') }}" @disabled($attempt) @if (! $attempt) required @endif>
                    @else
                        <label for="past-paper-response">Your answer</label>
                        <textarea id="past-paper-response" name="value" rows="6" maxlength="8000" @disabled($attempt) @if (! $attempt) required @endif>{{ $attempt?->response['value'] ?? old('value') }}</textarea>
                    @endif

                    @error('value')<p class="error" role="alert">{{ $message }}</p>@enderror

                    @if (! $attempt)
                        <button type="submit">Submit answer</button>
                    @else
                        <p><strong>Your submitted response:</strong>
                            @if (isset($attempt->response['values']))
                                {{ collect($attempt->response['values'])->join(', ') }}
                            @else
                                <span data-render-math>{{ $attempt->response['value'] ?? '' }}</span>
                            @endif
                        </p>
                    @endif
                </fieldset>
            </form>
            <x-read-aloud target="past-paper-question-content" language="en" />
        </section>

        <details>
            <summary>Compare with the extracted source page</summary>
            <p><strong>Question transcription</strong></p>
            <blockquote data-render-math>{{ $question->source_text }}</blockquote>
            @if ($attempt && $sourceChunk)
                <h2>Full extracted page {{ $question->page ?? 'unknown' }}</h2>
                <p class="muted">Compare the saved OCR text with the private original paper before relying on the transcription.</p>
                <blockquote data-render-math>{{ $sourceChunk->content }}</blockquote>
            @endif
            <a href="{{ route('materials.download', $material) }}">Open the original paper</a>
        </details>
    </div>
</x-layouts.app>
