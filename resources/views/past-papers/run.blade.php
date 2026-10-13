<x-layouts.app :title="'Past-paper practice | '.$material->title">
    <div class="stack paper-run" data-paper-exam data-expires-at="{{ $run->expires_at?->toIso8601String() }}" data-run-key="{{ $run->id }}">
        <a href="{{ $isMixed ? route('past-paper-mixed.index', $learningSpace) : route('materials.show', $material).'#past-paper-questions-heading' }}">{{ $isMixed ? 'Back to paper selection' : 'Back to '.$material->title }}</a>
        <header class="paper-exam-header">
            <div>
                <p class="eyebrow">{{ $isMixed ? 'Mixed-paper practice' : 'Exam practice' }} · {{ $questions->count() }} questions</p>
                <h1>{{ $isMixed ? 'Your selected papers' : $material->title }}</h1>
                @if ($isMixed)
                    <ul class="stack" aria-label="Papers in this practice set">
                        @foreach ($selectedMaterials as $paper)
                            <li>{{ $paper->title }}</li>
                        @endforeach
                    </ul>
                @elseif ($questions->contains(fn ($question) => $question->paper_year))
                    <p class="muted">Year {{ $questions->firstWhere('paper_year')?->paper_year }} is a clue from the paper title or file name.</p>
                @endif
                <p id="paper-exam-instructions" class="muted">Your answers are saved as you practise. Submit when you are ready.</p>
                <x-read-aloud target="paper-exam-instructions" language="en" />
            </div>
            <p class="paper-exam-clock" data-paper-clock role="timer" aria-label="Time remaining">{{ $run->duration_minutes }}:00</p>
        </header>

        <nav class="paper-question-nav" aria-label="Question navigation" data-paper-nav>
            <p><strong data-paper-progress>Question 1 of {{ $questions->count() }}</strong><span data-paper-status aria-live="polite"> Choose a question or move through the paper.</span></p>
            <ol>
                @foreach ($questions as $index => $question)
                    <li><button type="button" data-paper-go="{{ $index }}" aria-label="Go to question {{ $question->question_number }}">{{ $index + 1 }}</button></li>
                @endforeach
            </ol>
            <p class="muted">A dot means answered. “Review” marks a question to revisit.</p>
        </nav>

        <form method="POST" action="{{ route('past-paper-practice.submit', [$material, $run]) }}" class="paper-run-form" data-paper-form>
            @csrf
            @error('answers')<p class="error" role="alert">{{ $message }}</p>@enderror
            @foreach ($questions as $index => $question)
                @php($fieldId = 'paper-question-'.$question->id)
                <section class="quiz-question-surface card paper-exam-question" data-paper-question="{{ $index }}" aria-labelledby="{{ $fieldId }}" tabindex="-1">
                    <fieldset id="paper-question-content-{{ $question->id }}" class="quiz-question">
                        @php($sourcePaper = $question->material)
                        <legend id="{{ $fieldId }}">
                            <span class="eyebrow">{{ $sourcePaper->title }} · Question {{ $question->question_number }} · Page {{ $question->page ?? 'not specified' }} · {{ ucfirst(str_replace('_', ' ', $question->question_type)) }}</span>
                            @if ($question->paper_year)
                                <span class="muted">Year {{ $question->paper_year }} from the paper title or file name.</span>
                            @endif
                            @if ($question->marks)
                                <span class="eyebrow">{{ $question->marks }} {{ Illuminate\Support\Str::plural('mark', $question->marks) }} shown in the paper</span>
                            @endif
                            <span class="quiz-prompt" data-render-math>{{ $question->prompt }}</span>
                        </legend>
                        <p class="quiz-source-context"><span>Source:</span> {{ $sourcePaper->title }} · page {{ $question->page ?? 'not specified' }} · question {{ $question->question_number }}</p>

                        @if (in_array($question->question_type, ['single_choice', 'true_false'], true))
                            <div class="quiz-options">
                                @foreach ($question->options ?? [] as $option)
                                    <label class="quiz-option" for="{{ $fieldId }}-{{ $option['id'] }}">
                                        <input id="{{ $fieldId }}-{{ $option['id'] }}" type="radio" name="answers[{{ $question->id }}][value]" value="{{ $option['id'] }}">
                                        <span class="option-id">{{ $option['id'] }}</span><span data-render-math>{{ $option['text'] }}</span>
                                    </label>
                                @endforeach
                            </div>
                        @elseif ($question->question_type === 'multiple_select')
                            <p class="muted">Select every answer that applies. You may leave this question blank.</p>
                            <div class="quiz-options">
                                @foreach ($question->options ?? [] as $option)
                                    <label class="quiz-option" for="{{ $fieldId }}-{{ $option['id'] }}">
                                        <input id="{{ $fieldId }}-{{ $option['id'] }}" type="checkbox" name="answers[{{ $question->id }}][values][]" value="{{ $option['id'] }}">
                                        <span class="option-id">{{ $option['id'] }}</span><span data-render-math>{{ $option['text'] }}</span>
                                    </label>
                                @endforeach
                            </div>
                        @elseif ($question->question_type === 'fill_gap')
                            <label for="{{ $fieldId }}-response">Your answer</label>
                            <input id="{{ $fieldId }}-response" type="text" name="answers[{{ $question->id }}][value]" maxlength="8000">
                        @else
                            <label for="{{ $fieldId }}-response">Your answer</label>
                            <textarea id="{{ $fieldId }}-response" name="answers[{{ $question->id }}][value]" rows="5" maxlength="8000"></textarea>
                        @endif

                        <details>
                            <summary>Compare with the extracted question text</summary>
                            <p><strong>Question wording from the paper</strong></p>
                            <blockquote data-render-math>{{ $question->source_text }}</blockquote>
                            <p class="muted">Check the original paper if the transcription is unclear.</p>
                        </details>
                    </fieldset>
                    <x-read-aloud :target="'paper-question-content-'.$question->id" language="en" />
                    <div class="paper-question-actions">
                        <button type="button" class="button-secondary" data-paper-review aria-pressed="false">Mark for review</button>
                        <span data-paper-review-label></span>
                    </div>
                </section>
            @endforeach
            <div class="paper-exam-controls">
                <button type="button" class="button-secondary" data-paper-previous>Previous</button>
                <button type="button" data-paper-next>Next question</button>
                <button type="submit" class="button-secondary" data-paper-submit>Finish and submit</button>
            </div>
        </form>
    </div>
</x-layouts.app>
