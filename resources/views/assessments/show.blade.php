<x-layouts.app :title="'Practice — '.$assessment->concept->name">
    <a href="{{ route('study.show', $assessment->learningSpace) }}#concept-{{ $assessment->concept_id }}">← Back to your study path</a>
    <header class="hero compact">
        <p class="eyebrow">Source-grounded practice</p>
        <h1>{{ $assessment->concept->name }}</h1>
        <p>Three original exam-style questions based on your study materials.</p>
    </header>

    @if ($attempt)
        <section class="quiz-results" role="status" aria-live="polite">
            <p class="eyebrow">Practice result</p>
            <h2>{{ $attempt->correct_count }} of {{ $attempt->question_count }} correct</h2>
            <p>You can retry these questions or make a fresh practice set from your study path.</p>
        </section>
    @endif

    <form method="POST" action="{{ route('assessments.submit', $assessment) }}" class="quiz-form">
        @csrf
        <input type="hidden" name="attempt_uuid" value="{{ \Illuminate\Support\Str::uuid() }}">
        @foreach ($assessment->questions as $index => $question)
            @php($answer = $attempt?->answers->firstWhere('assessment_question_id', $question->id))
            <fieldset class="card quiz-question">
                <legend><span class="eyebrow">Question {{ $index + 1 }}</span>{{ $question->prompt }}</legend>
                <div class="quiz-options">
                    @foreach ($question->options as $option)
                        @php($isSelected = $answer?->selected_option_id === $option['id'])
                        @php($isCorrect = $attempt && $question->correct_option_id === $option['id'])
                        @if ($attempt)
                            <p class="quiz-option {{ $isCorrect ? 'answer-correct' : '' }} {{ $isSelected && ! $isCorrect ? 'answer-incorrect' : '' }}">
                                <span class="option-id">{{ $option['id'] }}.</span> {{ $option['text'] }}
                                @if ($isCorrect)<strong>Correct answer</strong>@elseif ($isSelected)<strong>Your answer</strong>@endif
                            </p>
                        @else
                            <label class="quiz-option" for="question-{{ $question->id }}-{{ $option['id'] }}">
                                <input id="question-{{ $question->id }}-{{ $option['id'] }}" type="radio" name="answers[{{ $question->id }}]" value="{{ $option['id'] }}" required>
                                <span class="option-id">{{ $option['id'] }}.</span> {{ $option['text'] }}
                            </label>
                        @endif
                    @endforeach
                </div>
                @if ($attempt)
                    <div class="answer-feedback"><strong>{{ $answer?->correct ? 'Correct' : 'Review this idea' }}</strong><p>{{ $question->explanation }}</p></div>
                    <details><summary>View source references</summary>
                        @foreach ($question->source_refs as $sourceId)
                            @if ($sources->has($sourceId))
                                @php($source = $sources->get($sourceId))
                                <p class="muted">{{ $source->material->title }} · Page {{ $source->page ?? 'unknown' }}</p>
                            @endif
                        @endforeach
                    </details>
                @endif
            </fieldset>
        @endforeach
        @unless ($attempt)<button>Submit answers</button>@endunless
    </form>

    @if ($recommendation)
        <aside class="study-prompt" aria-labelledby="revision-heading">
            <h2 id="revision-heading">A useful next step</h2>
            <p>Review <strong>{{ $recommendation['concept']->name }}</strong>, a prerequisite for this idea.
                @if ($recommendation['progress'])Your current evidence is {{ $recommendation['progress']['percentage'] }}% correct across {{ $recommendation['progress']['answered'] }} questions.
                @else This prerequisite has not been assessed yet. @endif
            </p>
            <a href="{{ route('study.show', $assessment->learningSpace) }}#concept-{{ $recommendation['concept']->id }}">Open its study materials and practice</a>
        </aside>
    @endif
</x-layouts.app>
