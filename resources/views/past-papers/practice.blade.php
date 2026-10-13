<x-layouts.app :title="'Past-paper practice | SelfLearn'">
    <div class="stack past-paper-practice">
        <a href="{{ route('materials.show', $material) }}#past-paper-questions-heading">Back to {{ $material->title }}</a>
        <header class="past-paper-practice-header">
            <p class="eyebrow">{{ $material->title }} · Past-paper practice</p>
            <h1>Question {{ $question->question_number }}</h1>
            <p id="past-paper-practice-instructions" class="lede">Page {{ $question->page ?? 'not specified' }} of your uploaded paper. This is one practice question, not a full exam.</p>
            <x-read-aloud target="past-paper-practice-instructions" language="en" />
            @if ($question->paper_year)
                <p class="muted">Year {{ $question->paper_year }} from the upload label.</p>
            @endif
            @if ($question->marks)
                <p class="muted">{{ $question->marks }} {{ Illuminate\Support\Str::plural('mark', $question->marks) }} shown in the paper.</p>
            @endif
        </header>

        @if ($attempt)
            <section class="quiz-results" role="status" aria-live="polite">
                <p class="eyebrow">Response submitted</p>
                @if ($attempt->outcome === 'correct')
                    <h2>{{ $attempt->marking_basis === 'confirmed_marking_scheme' ? 'Your answer matches the answer key you checked against the original.' : 'Your answer matches the answer text found in the paper.' }}</h2>
                    <p>This is practice feedback.</p>
                @elseif ($attempt->outcome === 'incorrect')
                    <h2>{{ $attempt->marking_basis === 'confirmed_marking_scheme' ? 'Your answer does not match the answer key you checked against the original.' : 'Your answer does not match the answer text found in the paper.' }}</h2>
                    <p>{{ $attempt->marking_basis === 'confirmed_marking_scheme' ? 'You checked this answer against both original pages. Review them if this result surprises you.' : 'The text may not match your original paper exactly. Check the original paper or a marking guide.' }}</p>
                @else
                    @if (in_array($question->question_type, ['fill_gap', 'structured_response'], true))
                        <h2>Your response is saved. No score was assigned.</h2>
                    @else
                        <h2>Your response is saved, but no score was assigned.</h2>
                        <p>There was no clear answer in the paper to check it against. Check your original paper or a matching marking guide.</p>
                    @endif
                @endif
                @if ($attempt->verifiedAnswer)
                    <div class="answer-feedback">
                        <strong>Answer from the key you checked</strong>
                        <p>Option {{ collect($attempt->verifiedAnswer->option_ids)->join(', ') }} · {{ $attempt->verifiedAnswer->markingSchemeMatch->markingScheme->title }} · page {{ $attempt->verifiedAnswer->source_page }}</p>
                        <blockquote class="answer-source-transcription">{{ $attempt->verifiedAnswer->answer_excerpt }}</blockquote>
                        <a href="{{ route('materials.download', $attempt->verifiedAnswer->markingSchemeMatch->markingScheme) }}" target="_blank" rel="noopener">Open the original answer key</a>
                    </div>
                @endif
                @if ($attempt->written_feedback_status === 'ready')
                    <div class="answer-feedback" aria-labelledby="written-feedback-heading">
                        <h2 id="written-feedback-heading">Understand the answer</h2>
                        <p data-render-math>{{ $attempt->written_feedback }}</p>
                        <ol class="stack">
                            @foreach ($attempt->written_feedback_steps ?? [] as $step)
                                <li data-render-math><strong>{{ $step['title'] }}</strong><br>{{ $step['detail'] }}</li>
                            @endforeach
                        </ol>
                        <p>This is practice feedback.</p>
                    </div>
                    @foreach ($writtenFeedbackEvidence as $evidence)
                        <section class="answer-feedback" aria-labelledby="written-feedback-source-{{ $loop->index }}">
                            <h3 id="written-feedback-source-{{ $loop->index }}">From your uploaded paper</h3>
                            <p class="quiz-source-context">{{ $evidence['title'] }} · page {{ $evidence['page'] ?? 'not specified' }} · question {{ $question->question_number }}</p>
                            <blockquote data-render-math>{{ $evidence['excerpt'] }}</blockquote>
                            @if ($evidence['continues'])
                                <p>More text is on this page.</p>
                            @endif
                        </section>
                    @endforeach
                @elseif ($attempt->written_feedback_status === 'needs_review')
                    <p class="notice">Your response is saved, but this page does not give enough detail for a clear explanation. No score was assigned.</p>
                @elseif ($attempt->written_feedback_status === 'unavailable')
                    <p class="notice">Your response is saved, but written feedback is not available right now. No score was assigned.</p>
                @endif
                @if ($question->answer_source === 'source_expected_response' && $question->expected_answer)
                    <div class="answer-feedback">
                        <strong>Answer text found in the paper</strong>
                        <p data-render-math>{{ $question->expected_answer }}</p>
                        <p class="muted">This answer text was found in the paper. Check it with a linked answer key before using it to score future practice.</p>
                    </div>
                @else
                    <div class="answer-feedback"><strong>No clear answer was found in the paper.</strong><p>Check the original paper or use a matching marking guide.</p></div>
                @endif
            </section>
        @else
            @if ($question->review_reason)
                <p class="notice" role="status">{{ $question->review_reason }}</p>
            @endif
            @if (in_array($question->question_type, ['fill_gap', 'structured_response'], true))
                <p>Submit your answer to see a step-by-step explanation based on this page. No score will be given unless a matching answer guide is confirmed.</p>
            @else
                <p>After you submit, we will compare your answer with the text in the paper. If there is no clear answer to compare, your response will be saved without a score.</p>
            @endif
        @endif

        <section class="quiz-question-surface card" aria-labelledby="past-paper-prompt">
            <form method="POST" action="{{ route('past-paper-questions.submit', [$material, $question]) }}" class="quiz-form">
                @csrf
                <fieldset id="past-paper-question-content" class="quiz-question">
                    <legend id="past-paper-prompt"><span class="eyebrow">{{ ucfirst(str_replace('_', ' ', $question->question_type)) }}</span><span class="quiz-prompt" data-render-math>{{ $question->prompt }}</span></legend>
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

                    <p class="quiz-source-context"><span>From your paper:</span> {{ $material->title }}
                        @if ($question->paper_year)
                            · year {{ $question->paper_year }} from upload label
                        @endif
                        · page {{ $question->page ?? 'not specified' }} · question {{ $question->question_number }}
                        @if ($question->marks)
                            · {{ $question->marks }} {{ Illuminate\Support\Str::plural('mark', $question->marks) }} shown in the paper
                        @endif
                    </p>

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
            <p><strong>Question wording from the paper</strong></p>
            <blockquote data-render-math>{{ $question->source_text }}</blockquote>
            @if ($attempt && $sourceChunk)
                <h2>Full extracted page {{ $question->page ?? 'unknown' }}</h2>
                <p class="muted">Compare the saved question text with your original paper before relying on it.</p>
                <blockquote data-render-math>{{ $sourceChunk->content }}</blockquote>
            @endif
            <a href="{{ route('materials.download', $material) }}">Open the original paper</a>
        </details>
    </div>
</x-layouts.app>
