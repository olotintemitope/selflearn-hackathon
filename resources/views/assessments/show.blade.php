<x-layouts.app :title="($assessment->mode === 'exam' ? 'Exam: ' : 'Guided practice: ').\App\Support\LearnerText::withoutEmDash($assessment->concept->name)">
    <a href="{{ route('study.show', $assessment->learningSpace) }}#concept-{{ $assessment->concept_id }}">← Back to your study path</a>
    <header class="hero compact">
        <p class="eyebrow">{{ $assessment->mode === 'exam' ? 'Exam mode' : 'Guided practice' }}</p>
        <h1>{{ \App\Support\LearnerText::withoutEmDash($assessment->concept->name) }}</h1>
        <p id="assessment-instructions">{{ $assessment->mode === 'exam' ? 'A short, 20-minute exam on this concept with three multiple-choice questions and one written response. Use only what you know. No hints or explanations appear until you submit.' : 'Three multiple-choice questions and one written response. Guided practice may include clearly labeled supplementary subject knowledge.' }}</p>
        <x-read-aloud target="assessment-instructions" language="en" />
    </header>

    @if ($outdatedQuestionVersion)
        <p class="notice" role="status">{{ $attempt ? 'This saved practice does not include step-by-step solutions.' : 'This practice set uses an earlier question format.' }} Generate a fresh set for exam-style questions with worked explanations. <a href="{{ route('study.show', $assessment->learningSpace).'#concept-'.$assessment->concept_id }}">Return to this concept</a></p>
    @endif

    @if ($attempt)
        @php($writtenQuestion = $assessment->questions->firstWhere('question_type', 'open_ended'))
        @php($writtenAnswer = $writtenQuestion ? $attempt->answers->firstWhere('assessment_question_id', $writtenQuestion->id) : null)
        <section class="quiz-results" role="status" aria-live="polite">
            <p class="eyebrow">{{ $assessment->mode === 'exam' ? 'Exam submitted' : 'Practice result' }}</p>
            @if ($attemptTimedOut)
                <p class="notice">Time expired before your answers reached us. No answers were scored. The questions below are shown as unanswered.</p>
            @endif
            <h2>{{ $attempt->correct_count }} of {{ $multipleChoiceCount }} multiple-choice answers correct</h2>
            @if (filled($writtenAnswer?->written_response))
                <p>Practice feedback, not an official exam result.</p>
            @else
                <p>No written response was submitted, so no written feedback was generated.</p>
            @endif
            @if ($assessment->mode !== 'exam' && $currentProgress)
                <p>Latest learning signal: {{ $currentProgress['percentage'] }}% across {{ $currentProgress['score_points'] }} of {{ $currentProgress['possible_points'] }} points. This planning signal uses this latest practice and any scored written answer. It is not a mastery score.</p>
                @if (in_array($currentProgress['written_status'], ['needs_review', 'failed', 'pending'], true))
                    <p>The written response is not included because it has no score yet. Use the feedback below to decide what to review.</p>
                @endif
            @endif
        </section>
    @endif

    @if ($assessment->mode === 'exam' && ! $attempt)
        <p class="exam-timer" role="timer" aria-live="off" data-exam-timer data-seconds="{{ $examSecondsRemaining }}">Time remaining: {{ gmdate('i:s', $examSecondsRemaining) }}</p>
        <p class="muted">20 minutes. When time expires, your current answers are submitted and unanswered questions count as unanswered.</p>
    @endif

    <form method="POST" action="{{ route('assessments.submit', $assessment) }}" class="quiz-form" @if ($assessment->mode === 'exam' && ! $attempt) data-exam-form @endif>
        @csrf
        <input type="hidden" name="attempt_uuid" value="{{ \Illuminate\Support\Str::uuid() }}">
        @foreach ($assessment->questions as $index => $question)
            @php($answer = $attempt?->answers->firstWhere('assessment_question_id', $question->id))
            @php($questionSources = collect($question->source_refs)->map(fn ($sourceId) => $sources->get($sourceId))->filter())
            @if ($question->question_type === 'open_ended')
                <div class="card quiz-question-surface">
                <fieldset id="assessment-question-{{ $question->id }}" class="quiz-question">
                    <legend data-render-math><span class="eyebrow">Written response</span><span class="quiz-prompt">{{ \App\Support\LearnerText::withoutEmDash($question->prompt) }}</span></legend>
                    @if ($questionSources->isNotEmpty() && ($assessment->mode !== 'exam' || $attempt))
                        <p class="quiz-source-context">
                            @if ($question->knowledge_basis === 'supplementary')
                                <span>Beyond this material:</span> This question uses standard subject knowledge to help you explore the topic further. Topic source:
                            @else
                                <span>Source:</span>
                            @endif
                            @foreach ($questionSources as $source)
                                {{ \App\Support\LearnerText::withoutEmDash($source->material->title) }} · page {{ $source->page ?? 'not specified' }}{{ $loop->last ? '' : ';' }}
                            @endforeach
                        </p>
                    @endif
                    @if ($attempt)
                        <div class="answer-feedback">
                            <strong>Your response</strong>
                            <p class="learner-response" data-render-math>{{ $answer?->written_response }}</p>
                        </div>
                        @if ($answer?->marking_status === 'marked')
                            <div class="answer-feedback" role="status" aria-live="polite">
                                <strong>Practice feedback · {{ $answer->points_awarded }} of {{ $answer->max_points }} points</strong>
                                <p data-render-math>{{ $answer->marking_feedback }}</p>
                                @foreach ($answer->marking_criteria_results ?? [] as $criterion)
                                    <p data-render-math><strong>{{ $criterion['points_awarded'] }} points</strong>: {{ \App\Support\LearnerText::withoutEmDash($criterion['feedback']) }}</p>
                                @endforeach
                            </div>
                            <details>
                                <summary>View sources used for feedback</summary>
                                @foreach ($answer->marking_citations ?? [] as $sourceId)
                                    @if ($sources->has($sourceId))
                                        @php($source = $sources->get($sourceId))
                                        <p class="muted">{{ \App\Support\LearnerText::withoutEmDash($source->material->title) }} · Page {{ $source->page ?? 'unknown' }}</p>
                                    @endif
                                @endforeach
                            </details>
                        @elseif ($answer?->marking_status === 'needs_review')
                            <p class="answer-feedback" role="status">{{ \App\Support\LearnerText::withoutEmDash($answer->marking_feedback) }} No score was assigned.</p>
                            <button type="submit" form="new-practice">Try a new practice set</button>
                        @elseif ($answer?->marking_status === 'failed')
                            <p class="answer-feedback" role="status">{{ \App\Support\LearnerText::withoutEmDash($answer->marking_feedback) }}</p>
                            <button type="submit" form="retry-marking">Try AI marking again</button>
                        @elseif ($answer?->marking_status === 'not_applicable')
                            <p class="answer-feedback" role="status">{{ \App\Support\LearnerText::withoutEmDash($answer->marking_feedback) }}</p>
                        @else
                            <p class="answer-feedback" role="status">Your response is waiting to be marked.</p>
                        @endif
                        @if ($question->solution_steps)
                            <section class="solution-breakdown" aria-labelledby="written-solution-{{ $question->id }}">
                                <h3 id="written-solution-{{ $question->id }}">A worked answer, step by step</h3>
                                <ol>
                                    @foreach ($question->solution_steps as $step)
                                        <li><strong>{{ \App\Support\LearnerText::withoutEmDash($step['title']) }}</strong><p data-render-math>{{ \App\Support\LearnerText::withoutEmDash($step['content']) }}</p></li>
                                    @endforeach
                                </ol>
                            </section>
                        @endif
                    @else
                        <label for="response-{{ $question->id }}">Your answer</label>
                        <textarea id="response-{{ $question->id }}" name="responses[{{ $question->id }}]" rows="6" maxlength="12000" @if ($assessment->mode !== 'exam') required @endif aria-describedby="response-help-{{ $question->id }}">{{ old('responses.'.$question->id) }}</textarea>
                        @if ($assessment->mode !== 'exam')<p id="response-help-{{ $question->id }}" class="muted">Explain in your own words. Feedback will use your materials.</p>@endif
                    @endif
                    @if ($attempt)
                        <details><summary>View source references for this question</summary>
                            @foreach ($question->source_refs as $sourceId)
                                @if ($sources->has($sourceId))
                                    @php($source = $sources->get($sourceId))
                                    <p class="muted">{{ \App\Support\LearnerText::withoutEmDash($source->material->title) }} · Page {{ $source->page ?? 'unknown' }}</p>
                                @endif
                            @endforeach
                        </details>
                    @endif
                </fieldset>
                <x-read-aloud :target="'assessment-question-'.$question->id" language="en" />
                </div>
            @else
                <div class="card quiz-question-surface">
                <fieldset id="assessment-question-{{ $question->id }}" class="quiz-question">
                    <legend data-render-math><span class="eyebrow">Question {{ $loop->iteration }}</span><span class="quiz-prompt">{{ \App\Support\LearnerText::withoutEmDash($question->prompt) }}</span></legend>
                    @if ($questionSources->isNotEmpty() && ($assessment->mode !== 'exam' || $attempt))
                        <p class="quiz-source-context">
                            @if ($question->knowledge_basis === 'supplementary')
                                <span>Beyond this material:</span> This question uses standard subject knowledge to help you explore the topic further. Topic source:
                            @else
                                <span>Source:</span>
                            @endif
                            @foreach ($questionSources as $source)
                                {{ \App\Support\LearnerText::withoutEmDash($source->material->title) }} · page {{ $source->page ?? 'not specified' }}{{ $loop->last ? '' : ';' }}
                            @endforeach
                        </p>
                    @endif
                    <div class="quiz-options">
                        @foreach ($question->options as $option)
                            @php($isSelected = $answer?->selected_option_id === $option['id'])
                            @php($isCorrect = $attempt && $question->correct_option_id === $option['id'])
                            @if ($attempt)
                                <p class="quiz-option {{ $isCorrect ? 'answer-correct' : '' }} {{ $isSelected && ! $isCorrect ? 'answer-incorrect' : '' }}">
                                    <span class="option-id">{{ $option['id'] }}.</span> <span data-render-math>{{ \App\Support\LearnerText::withoutEmDash($option['text']) }}</span>
                                    @if ($isCorrect)<strong>Correct answer</strong>@elseif ($isSelected)<strong>Your answer</strong>@endif
                                </p>
                            @else
                                <label class="quiz-option" for="question-{{ $question->id }}-{{ $option['id'] }}">
                                    <input id="question-{{ $question->id }}-{{ $option['id'] }}" type="radio" name="answers[{{ $question->id }}]" value="{{ $option['id'] }}" @if ($assessment->mode !== 'exam') required @endif>
                                    <span class="option-id">{{ $option['id'] }}.</span> <span data-render-math>{{ \App\Support\LearnerText::withoutEmDash($option['text']) }}</span>
                                </label>
                            @endif
                        @endforeach
                    </div>
                    @if ($attempt)
                        <div class="answer-feedback"><strong>{{ blank($answer?->selected_option_id) ? 'Unanswered' : ($answer->correct ? 'Correct' : 'Review this idea') }}</strong><p data-render-math>{{ blank($answer?->selected_option_id) ? ($attemptTimedOut ? 'You did not answer before time ran out.' : 'No answer was submitted for this question.') : \App\Support\LearnerText::withoutEmDash($question->explanation) }}</p></div>
                        @if ($question->solution_steps)
                            <section class="solution-breakdown" aria-labelledby="solution-{{ $question->id }}">
                                <h3 id="solution-{{ $question->id }}">How to work it out</h3>
                                <ol>
                                    @foreach ($question->solution_steps as $step)
                                        <li><strong>{{ \App\Support\LearnerText::withoutEmDash($step['title']) }}</strong><p data-render-math>{{ \App\Support\LearnerText::withoutEmDash($step['content']) }}</p></li>
                                    @endforeach
                                </ol>
                            </section>
                        @endif
                        <details><summary>View source references</summary>
                            @foreach ($question->source_refs as $sourceId)
                                @if ($sources->has($sourceId))
                                    @php($source = $sources->get($sourceId))
                                    <p class="muted">{{ \App\Support\LearnerText::withoutEmDash($source->material->title) }} · Page {{ $source->page ?? 'unknown' }}</p>
                                @endif
                            @endforeach
                        </details>
                    @endif
                </fieldset>
                <x-read-aloud :target="'assessment-question-'.$question->id" language="en" />
                </div>
            @endif
        @endforeach
        @unless ($attempt)<button>{{ $assessment->mode === 'exam' ? 'Submit exam' : 'Submit practice' }}</button>@endunless
    </form>

    @if ($attempt && $attempt->answers->firstWhere('marking_status', 'needs_review'))
        <form id="new-practice" method="POST" action="{{ route('assessments.store', [$assessment->learningSpace, $assessment->concept]) }}">@csrf</form>
    @endif

    @if ($attempt && $attempt->answers->firstWhere('marking_status', 'failed'))
        <form id="retry-marking" method="POST" action="{{ route('assessments.mark-open-ended', [$assessment, $attempt]) }}">@csrf</form>
    @endif

    @if ($attempt && $recommendedStep && $assessment->mode !== 'exam')
        @php($nextConcept = $recommendation['concept'] ?? $recommendedStep['item']->concept)
        <aside class="study-prompt" aria-labelledby="revision-heading">
            @if ($recommendation)
                <h2 id="revision-heading">Strengthen a prerequisite first</h2>
                <p>Review <strong>{{ $nextConcept->name }}</strong>, which supports this idea.
                    @if ($recommendation['progress'])Your latest evidence is {{ $recommendation['progress']['percentage'] }}% across {{ $recommendation['progress']['score_points'] }} of {{ $recommendation['progress']['possible_points'] }} points.
                    @else This prerequisite has not been assessed yet. @endif
                </p>
            @elseif ($nextConcept->id === $assessment->concept_id)
                <h2 id="revision-heading">Review this idea before moving on</h2>
                <p>Your latest practice suggests another explanation and check would help. We use 70% as a review prompt, not a mastery boundary.</p>
            @else
                <h2 id="revision-heading">Your next lesson</h2>
                <p>Your latest practice suggests you can continue to <strong>{{ $nextConcept->name }}</strong>. The path will still let you revisit earlier topics.</p>
            @endif
            <div class="actions">
                <a class="button-link" href="{{ route('study.show', $assessment->learningSpace) }}?concept={{ $nextConcept->id }}#question">Ask the tutor about {{ $nextConcept->name }}</a>
                <a href="{{ route('study.show', $assessment->learningSpace) }}#concept-{{ $nextConcept->id }}">Read its source and practice</a>
            </div>
        </aside>
    @endif
    @if ($attempt && $assessment->mode === 'exam')
        <aside class="study-prompt" aria-labelledby="exam-review-heading">
            <h2 id="exam-review-heading">Want to learn from this result?</h2>
            <p>Your score is separate from study guidance. Review the worked answers above, or switch to guided practice and ask the tutor about this concept.</p>
            <div class="actions"><a class="button-link" href="{{ route('study.show', $assessment->learningSpace) }}?concept={{ $assessment->concept_id }}#question">Ask the tutor</a><a href="{{ route('study.show', $assessment->learningSpace) }}#concept-{{ $assessment->concept_id }}">Return to the study path</a></div>
        </aside>
    @endif
</x-layouts.app>
