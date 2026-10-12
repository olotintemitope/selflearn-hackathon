<x-layouts.app :title="'Study path: '.$learningSpace->title">
    <a href="{{ route('materials.index') }}">← All materials</a>
    <header class="hero compact study-hero">
        <p class="eyebrow">Adaptive study</p>
        <h1>Your learning path</h1>
        <p>Read the source-backed idea, ask for a step-by-step explanation, then practise with feedback.</p>
    </header>

    <section class="card goal-progress" aria-labelledby="goal-progress-heading">
        <div>
            <p class="eyebrow">Your goal</p>
            <h2 id="goal-progress-heading">{{ $learningSpace->goal ?: $learningSpace->title }}</h2>
            @if ($learningSpace->exam_date)<p class="muted">Target date: <time datetime="{{ $learningSpace->exam_date->toDateString() }}">{{ $learningSpace->exam_date->format('j M Y') }}</time></p>@endif
        </div>
        <div class="goal-progress-meter">
            @if ($pathProgress['total_concepts'] > 0)
                <p><strong>{{ $pathProgress['practised_concepts'] }} of {{ $pathProgress['total_concepts'] }} concepts practised</strong></p>
                <progress aria-label="Concepts practised in your learning path" value="{{ $pathProgress['practised_concepts'] }}" max="{{ $pathProgress['total_concepts'] }}">{{ $pathProgress['percentage'] }}%</progress>
                @if ($pathProgress['complete'])
                    <p role="status">You have practised every concept in this path. Practice completion is a progress checkpoint, not a mastery score.</p>
                @else
                    <p class="muted">Practise each concept once to complete this path. Your next suggested lesson is below.</p>
                @endif
            @else
                <p>Your path is ready to grow when related study material is available.</p>
            @endif
        </div>
    </section>

    @if ($nextStep)
        @php($nextConcept = $nextStep['item']->concept)
        <section class="card next-step" aria-labelledby="next-step-heading">
            <p class="eyebrow">Your recommended next step</p>
            <h2 id="next-step-heading">{{ $nextConcept->name }}</h2>
            <p>{{ $nextStep['reason'] }}</p>
            <div class="guided-session" data-guided-session data-session-key="{{ $learningSpace->id }}:{{ $nextStep['item']->concept_id }}" data-duration="{{ $nextStep['minutes'] * 60 }}" aria-labelledby="session-heading">
                <h3 id="session-heading">Suggested {{ $nextStep['minutes'] }}-minute session</h3>
                <ol class="session-phases">
                    <li data-session-phase="read"><strong>Read · {{ $nextStep['read_minutes'] }} min</strong><span>Open the source evidence for this idea.</span></li>
                    <li data-session-phase="tutor"><strong>Learn · {{ $nextStep['tutor_minutes'] }} min</strong><span>Ask the tutor, then answer its check question.</span></li>
                    <li data-session-phase="practice"><strong>Practise · {{ $nextStep['practice_minutes'] }} min</strong><span>Try a question and review the worked feedback.</span><a href="#concept-{{ $nextStep['item']->concept_id }}">Go to practice</a></li>
                </ol>
                <div class="session-controls">
                    <button type="button" data-session-toggle>Start session</button>
                    <button type="button" class="link-button" data-session-reset hidden>Reset</button>
                    <span class="session-clock" data-session-clock aria-live="off" aria-label="15 minutes remaining">15:00</span>
                    <p data-session-status role="status" aria-live="polite">Suggested order builds one idea on the last. You can explore ahead, then ask the tutor to revisit a foundation if something feels unclear.</p>
                </div>
            </div>
            <div class="actions">
                <a class="button-link" href="#concept-{{ $nextStep['item']->concept_id }}">Read and practise</a>
                <a href="{{ route('study.show', $learningSpace) }}?concept={{ $nextStep['item']->concept_id }}#question">Ask for a lesson</a>
            </div>
        </section>
    @endif

    <div class="study-layout">
        <section aria-labelledby="path-heading">
            <div class="section-heading"><div><p class="eyebrow">Path version {{ $path->version }}</p><h2 id="path-heading">Prerequisite-first order</h2></div></div>
            @if ($path->items->isEmpty())
                <p class="notice">Upload and process a material before building a study path.</p>
            @else
                <ol class="learning-path">
                    @php($clockDiagramConceptId = $path->items->first(fn ($pathItem) => $pathItem->concept->sources->contains(fn ($source) => ($source->locator['clock_diagrams']['status'] ?? null) === 'learner_confirmed'))?->concept_id)
                    @foreach ($path->items as $item)
                        <li class="card" id="concept-{{ $item->concept_id }}">
                            <span class="path-number" aria-hidden="true">{{ $item->position }}</span>
                            <div><h3>{{ $item->concept->name }}</h3><p id="concept-explanation-{{ $item->concept_id }}" data-render-math>{{ $item->concept->description }}</p>
                            @if ($item->concept_id === $clockDiagramConceptId)
                                <x-confirmed-clock-diagrams :sources="$item->concept->sources" :concept-id="$item->concept_id" />
                            @endif
                            <x-read-aloud :target="'concept-explanation-'.$item->concept_id" language="en" />
                            @if ($item->concept->sources->isNotEmpty())
                                <details data-session-read>
                                    <summary>Read the source evidence</summary>
                                    @foreach ($item->concept->sources->take(2) as $source)
                                        <blockquote data-render-math><strong>{{ $source->material->title }} · Page {{ $source->page ?? 'unknown' }}</strong><br>{{ \Illuminate\Support\Str::limit($source->content, 900) }}</blockquote>
                                    @endforeach
                                </details>
                            @endif
                            @if ($item->concept->prerequisites->isNotEmpty())<p class="muted">Builds on {{ $item->concept->prerequisites->pluck('name')->join(', ') }}</p>@endif
                            @php($progress = $assessmentProgress[$item->concept_id] ?? null)
                            @if ($progress)
                                <p class="muted">Latest practice: {{ $progress['percentage'] }}% ({{ $progress['score_points'] }} of {{ $progress['possible_points'] }} points).
                                    @if ($progress['written_points'] !== null) Written response: {{ $progress['written_points'] }}/{{ $progress['written_max_points'] }} provisional AI points.
                                    @elseif (in_array($progress['written_status'], ['needs_review', 'failed', 'pending'], true)) Written response is not included because it has no scored feedback yet.
                                    @endif
                                </p>
                            @elseif (! isset($tutorCheckProgress[$item->concept_id]))
                                <p class="muted">Not assessed yet</p>
                            @endif
                            @if (isset($tutorCheckProgress[$item->concept_id]))
                                <p class="muted">Tutor check, formative and not a score: {{ match ($tutorCheckProgress[$item->concept_id]) {
                                    'understood' => 'you showed the key idea in your answer. You can continue and ask for another explanation any time.',
                                    'developing' => 'you are building your understanding. Ask the tutor about the part that still feels unclear.',
                                    'needs_support' => 'another explanation or a smaller guided step may help.',
                                } }}</p>
                            @endif
                            @if ($item->concept_id === $nextStep['item']->concept_id)<p class="eyebrow">Recommended next</p>@endif
                            @if ($item->concept->sources_exists)
                                <div class="actions">
                                    <form method="POST" action="{{ route('assessments.store', [$learningSpace, $item->concept]) }}" data-session-practice>@csrf<input type="hidden" name="mode" value="guided"><button>{{ $progress ? 'Guided practice again' : 'Guided practice' }}</button></form>
                                    <form method="POST" action="{{ route('assessments.store', [$learningSpace, $item->concept]) }}">@csrf<input type="hidden" name="mode" value="exam"><button class="button-secondary">Start exam</button></form>
                                    <a href="{{ route('study.show', $learningSpace) }}?concept={{ $item->concept_id }}#question">Ask the tutor about this</a>
                                </div>
                            @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>

        <aside class="tutor-section" aria-labelledby="tutor-heading">
            <header class="tutor-header"><p class="eyebrow">Grounded tutor</p><h2 id="tutor-heading">Ask your materials</h2><p class="muted">Answers cite exact excerpts. Supplementary knowledge is labelled separately.</p></header>
            @include('study.partials.tutor-language', ['learningSpace' => $learningSpace])
            <div class="conversation" aria-live="polite" aria-label="Conversation with your tutor">
                @forelse ($messages as $message)
                    <article class="message message-{{ $message->role }} {{ $message->status === 'failed' ? 'error' : '' }}">
                        <h3>{{ $message->role === 'user' ? 'You' : 'Tutor' }}</h3>
                        @if ($message->status === 'failed')
                            @php($tutorFallbackCopy = app(\App\Services\Learning\TutorFallbackCopy::class))
                            @php($localizedCitationFailure = $tutorFallbackCopy->text($message->language, 'citation_unavailable'))
                            @php($citationFailure = $message->failure_message === 'The tutor returned an unresolved citation.' || $message->failure_message === $localizedCitationFailure)
                            @if ($citationFailure)
                                <p>{{ $localizedCitationFailure }}</p>
                            @elseif (in_array($message->failure_message, ['The tutor returned malformed output.', 'The tutor returned malformed supplementary content.'], true))
                                <p>I could not verify a reliable lesson against your materials. Your question is saved. Choose this topic in your path to try a source-matched lesson, or make your question more specific.</p>
                            @else
                                <p>{{ $message->failure_message }}</p>
                            @endif
                            @if (! $citationFailure)
                                <p>Your question is saved in the conversation above. You can copy it back into the box below, try a narrower explanation, or continue with the learning path.</p>
                            @endif
                            <a href="#question">{{ $citationFailure ? $tutorFallbackCopy->text($message->language, 'go_to_question') : 'Go to your question' }}</a>
                            <a href="#path-heading">{{ $citationFailure ? $tutorFallbackCopy->text($message->language, 'continue_path') : 'Continue with your learning path' }}</a>
                        @else
                            @if ($message->status === 'fallback')<p class="evidence-label">{{ app(\App\Services\Learning\TutorFallbackCopy::class)->text($message->language, 'guided_note_label') }}</p>@endif
                            <x-learner-markdown :content="$message->content" class="tutor-answer" :id="'tutor-answer-'.$message->id" />
                            <x-read-aloud :target="'tutor-answer-'.$message->id" :language="$message->language ?: 'en'" />
                            @if ($message->understanding_check && $message->understanding_check !== 'not_applicable')
                                <p class="evidence-label" role="status">Tutor check, formative only:
                                    @if ($message->understanding_check === 'understood') You explained the key idea clearly. You can continue, and revisit this any time.
                                    @elseif ($message->understanding_check === 'developing') You are building the idea. Ask about the part that still feels unclear.
                                    @else Another explanation may help. You can ask for a smaller step or try the check again.
                                    @endif
                                </p>
                                @if ($message->understanding_check === 'needs_support' && $message->concept_id)
                                    <a href="{{ route('study.show', $learningSpace) }}?concept={{ $message->concept_id }}#question">Ask for a smaller step</a>
                                @endif
                            @endif
                            @if ($message->insufficient_evidence)<p class="evidence-label">{{ app(\App\Services\Learning\TutorFallbackCopy::class)->text($message->language, 'insufficient_label') }}</p>@endif
                            @foreach ($message->citations as $citation)
                                @php($chunk = \App\Models\MaterialChunk::with('material')->find($citation['chunk_id']))
                                @if ($chunk && $chunk->material->learning_space_id === $learningSpace->id)
                                    <blockquote data-render-math><strong>{{ $chunk->material->title }} · Page {{ $chunk->page ?? 'unknown' }}</strong><br>{{ $citation['excerpt'] }}</blockquote>
                                @endif
                            @endforeach
                            @foreach ($message->supplementary_sections as $section)
                                <aside class="supplementary"><strong>Supplementary: {{ $section['title'] }}</strong><x-learner-markdown :content="$section['content']" /></aside>
                            @endforeach
                        @endif
                    </article>
                @empty
                    <p class="empty-state">No questions yet. Try asking about one concept in your path.</p>
                @endforelse
            </div>

            <form id="question" class="question-form" method="POST" action="{{ route('tutor.ask', $learningSpace) }}">
                @csrf
                @if ($suggestedTutorConceptId)<input type="hidden" name="concept_id" value="{{ old('concept_id', $suggestedTutorConceptId) }}">@endif
                <label for="tutor-question">Your question <span class="muted">You can edit the suggested question.</span><textarea id="tutor-question" name="question" rows="3" maxlength="2000" required placeholder="Ask about a concept in these materials…">{{ old('question', $retryQuestion ?? $suggestedTutorPrompt ?? '') }}</textarea></label>
                <button>Send question</button>
            </form>
        </aside>
    </div>

    <p><a href="{{ route('clock-practice.show') }}">Try standalone generated clock practice (demo)</a></p>
</x-layouts.app>
