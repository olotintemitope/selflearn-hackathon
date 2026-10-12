<x-layouts.app :title="'Practice results | '.$material->title">
    @php
        $correctCount = $attempts->where('outcome', 'correct')->count();
        $incorrectCount = $attempts->where('outcome', 'incorrect')->count();
        $ungradedCount = $attempts->where('outcome', 'submitted_ungraded')->count();
        $unansweredCount = $questions->count() - $attempts->count();
        $timedOut = $run->expires_at && $run->submitted_at && $run->submitted_at->greaterThanOrEqualTo($run->expires_at);
    @endphp
    <div class="stack paper-run">
        <a href="{{ $isMixed ? route('past-paper-mixed.index', $learningSpace) : route('materials.show', $material).'#past-paper-questions-heading' }}">{{ $isMixed ? 'Back to paper selection' : 'Back to '.$material->title }}</a>
        <header>
            <p class="eyebrow">Practice submitted</p>
            <h1>{{ $isMixed ? 'Your mixed-paper practice' : 'Your paper practice' }}</h1>
            @if ($isMixed)
                <p>Questions came from these selected papers:</p>
                <ul class="stack">@foreach ($selectedMaterials as $paper)<li>{{ $paper->title }}</li>@endforeach</ul>
            @endif
            @if ($timedOut)
                <p class="notice" role="status">Time expired before your answers reached us. No answers were scored. The questions below are shown as unanswered.</p>
            @endif
            @if (! $isMixed && $questions->contains(fn ($question) => $question->paper_year))
                <p class="muted">Year {{ $questions->firstWhere('paper_year')?->paper_year }} from the paper title or file name.</p>
            @endif
            <p id="paper-result-summary" class="lede">{{ $questions->count() }} questions · {{ $correctCount }} matched · {{ $incorrectCount }} did not match · {{ $ungradedCount }} saved without a score · {{ $unansweredCount }} unanswered.</p>
            <x-read-aloud target="paper-result-summary" language="en" />
            <p class="muted">A match compares your answer with text found in your paper. It is not an official mark. Written answers are saved without a score because no matching marking guide is available.</p>
        </header>

        @foreach ($questions as $question)
            @php($attempt = $attempts->get($question->id))
            @php($sourcePaper = $question->material)
            <section id="paper-result-question-{{ $question->id }}" class="quiz-question-surface card" aria-labelledby="result-question-{{ $question->id }}">
                <h2 id="result-question-{{ $question->id }}">{{ $sourcePaper->title }} · Question {{ $question->question_number }} · Page {{ $question->page ?? 'not specified' }}</h2>
                @if ($question->paper_year)
                    <p class="muted">Year {{ $question->paper_year }} from the paper title or file name, not checked against official records.</p>
                @endif
                @if ($question->marks)
                    <p class="muted">{{ $question->marks }} {{ Illuminate\Support\Str::plural('mark', $question->marks) }} shown in the paper.</p>
                @endif
                <p data-render-math>{{ $question->prompt }}</p>
                <p class="quiz-source-context"><span>Source:</span> {{ $sourcePaper->title }} · page {{ $question->page ?? 'not specified' }} · question {{ $question->question_number }}
                    @if ($question->marks)
                        · {{ $question->marks }} {{ Illuminate\Support\Str::plural('mark', $question->marks) }} shown in the paper
                    @endif
                </p>
                @if (! $attempt)
                    <p class="notice">{{ $timedOut ? 'This question was unanswered when time expired.' : 'You left this question unanswered.' }}</p>
                @elseif ($attempt->outcome === 'correct')
                    <p class="answer-correct">Your answer matches text found in the paper. This has not been checked against an official marking guide.</p>
                @elseif ($attempt->outcome === 'incorrect')
                    <p class="answer-incorrect">Your answer does not match the answer text found in this paper. The text may not match your original paper exactly.</p>
                @else
                    <p class="notice">Your response was saved, but there was no clear answer in the paper to check it against.</p>
                @endif
                @if ($attempt)
                    <div class="answer-feedback">
                        <strong>Your response</strong>
                        <p data-render-math>{{ isset($attempt->response['values']) ? collect($attempt->response['values'])->join(', ') : ($attempt->response['value'] ?? '') }}</p>
                    </div>
                @endif
                @if ($question->answer_source === 'source_expected_response' && $question->expected_answer)
                    <div class="answer-feedback">
                        <strong>Answer text from the paper · not verified</strong>
                        <p data-render-math>{{ $question->expected_answer }}</p>
                        <p class="muted">This answer text was found in your paper. Check it against the original or a matching marking guide.</p>
                    </div>
                @else
                    <div class="answer-feedback"><strong>No clear answer was found in the paper.</strong><p>Check the original paper or use a matching marking guide.</p></div>
                @endif
                <details>
                    <summary>Compare with the extracted source page</summary>
                    <p><strong>Question wording from the paper</strong></p>
                    <blockquote data-render-math>{{ $question->source_text }}</blockquote>
                    @if ($question->sourceChunk)
                        <h3>Extracted page {{ $question->page ?? 'unknown' }}</h3>
                        <blockquote data-render-math>{{ $question->sourceChunk->content }}</blockquote>
                    @endif
                    <a href="{{ route('materials.download', $sourcePaper) }}">Open this original paper</a>
                </details>
                <x-read-aloud :target="'paper-result-question-'.$question->id" language="en" />
            </section>
        @endforeach
    </div>
</x-layouts.app>
