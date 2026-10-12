<x-layouts.app :title="'Practice results | '.$material->title">
    @php
        $correctCount = $attempts->where('outcome', 'correct')->count();
        $incorrectCount = $attempts->where('outcome', 'incorrect')->count();
        $ungradedCount = $attempts->where('outcome', 'submitted_ungraded')->count();
        $unansweredCount = $questions->count() - $attempts->count();
        $timedOut = $run->expires_at && $run->submitted_at && $run->submitted_at->greaterThanOrEqualTo($run->expires_at);
    @endphp
    <div class="stack paper-run">
        <a href="{{ route('materials.show', $material) }}#past-paper-questions-heading">Back to {{ $material->title }}</a>
        <header>
            <p class="eyebrow">Practice submitted</p>
            <h1>Your paper practice</h1>
            @if ($timedOut)
                <p class="notice" role="status">Time expired before your answers reached us. No answers were scored. The questions below are shown as unanswered.</p>
            @endif
            @if ($questions->contains(fn ($question) => $question->paper_year))
                <p class="muted">Year {{ $questions->firstWhere('paper_year')?->paper_year }} from the upload title or filename.</p>
            @endif
            <p id="paper-result-summary" class="lede">{{ $questions->count() }} detected questions · {{ $correctCount }} matched · {{ $incorrectCount }} did not match · {{ $ungradedCount }} saved without a score · {{ $unansweredCount }} unanswered.</p>
            <x-read-aloud target="paper-result-summary" language="en" />
            <p class="muted">A match is only a comparison with answer text detected in your upload. It is not an official mark. Written responses are saved without an AI score in this imported-paper mode.</p>
        </header>

        @foreach ($questions as $question)
            @php($attempt = $attempts->get($question->id))
            <section id="paper-result-question-{{ $question->id }}" class="quiz-question-surface card" aria-labelledby="result-question-{{ $question->id }}">
                <h2 id="result-question-{{ $question->id }}">Question {{ $question->question_number }} · Page {{ $question->page ?? 'not specified' }}</h2>
                @if ($question->marks)
                    <p class="muted">{{ $question->marks }} {{ Illuminate\Support\Str::plural('mark', $question->marks) }} detected.</p>
                @endif
                <p data-render-math>{{ $question->prompt }}</p>
                <p class="quiz-source-context"><span>Source:</span> {{ $material->title }} · page {{ $question->page ?? 'not specified' }} · question {{ $question->question_number }}
                    @if ($question->marks)
                        · {{ $question->marks }} {{ Illuminate\Support\Str::plural('mark', $question->marks) }} detected
                    @endif
                </p>
                @if (! $attempt)
                    <p class="notice">{{ $timedOut ? 'This question was unanswered when time expired.' : 'You left this question unanswered.' }}</p>
                @elseif ($attempt->outcome === 'correct')
                    <p class="answer-correct">Your answer matches the detected source response. This has not been verified against an official marking scheme.</p>
                @elseif ($attempt->outcome === 'incorrect')
                    <p class="answer-incorrect">Your answer does not match the detected source response. OCR errors may affect this comparison.</p>
                @else
                    <p class="notice">Your response was saved, but no score was assigned because there was no unambiguous objective key.</p>
                @endif
                @if ($attempt)
                    <div class="answer-feedback">
                        <strong>Your response</strong>
                        <p data-render-math>{{ isset($attempt->response['values']) ? collect($attempt->response['values'])->join(', ') : ($attempt->response['value'] ?? '') }}</p>
                    </div>
                @endif
                @if ($question->answer_source === 'source_expected_response' && $question->expected_answer)
                    <div class="answer-feedback">
                        <strong>Detected source response · unverified</strong>
                        <p data-render-math>{{ $question->expected_answer }}</p>
                        <p class="muted">This wording was detected in the uploaded source. Compare it with the original paper or a matching marking scheme.</p>
                    </div>
                @else
                    <div class="answer-feedback"><strong>No answer key detected.</strong><p>We have not generated or guessed one.</p></div>
                @endif
                <details>
                    <summary>Compare with the extracted source page</summary>
                    <p><strong>Question transcription</strong></p>
                    <blockquote data-render-math>{{ $question->source_text }}</blockquote>
                    @if ($question->sourceChunk)
                        <h3>Extracted page {{ $question->page ?? 'unknown' }}</h3>
                        <blockquote data-render-math>{{ $question->sourceChunk->content }}</blockquote>
                    @endif
                    <a href="{{ route('materials.download', $material) }}">Open the original paper</a>
                </details>
                <x-read-aloud :target="'paper-result-question-'.$question->id" language="en" />
            </section>
        @endforeach
    </div>
</x-layouts.app>
