<x-layouts.app :title="$material->title.' | SelfLearn'">
    <a href="{{ route('materials.index') }}#space-{{ $material->learning_space_id }}-materials">← Back to this study space</a>
    <section class="hero compact material-hero">
        <p class="eyebrow">{{ $material->learningSpace->title }} · {{ $material->roleLabel() }}</p>
        <h1>{{ $material->title }}</h1>
        <div class="actions"><span class="status status-{{ $material->status }}">{{ ucfirst($material->status) }}</span><a href="{{ route('materials.download', $material) }}">Download original</a></div>
    </section>
    @if (in_array($material->status, ['pending', 'processing']))
        <div class="card" role="status" aria-live="polite">
            <h2>Preparing your study guide</h2>
            @php($extractionProgress = $material->metadata['extraction_progress'] ?? null)
            @php($processingStage = $material->metadata['processing_stage'] ?? 'extraction')
            @php($stagePosition = ['extraction' => 0, 'relevance' => 1, 'concepts' => 2][$processingStage] ?? 0)
            @if ($processingStage === 'extraction' && is_array($extractionProgress) && ($extractionProgress['total_pages'] ?? 0) > 0)
                @php($completedPages = min($extractionProgress['total_pages'], max(0, $extractionProgress['next_page'] - 1)))
                <p>Reading your material, page {{ $completedPages }} of {{ $extractionProgress['total_pages'] }}. Page extraction is in progress.</p>
                <progress aria-label="Pages read" value="{{ $completedPages }}" max="{{ $extractionProgress['total_pages'] }}">{{ $completedPages }} of {{ $extractionProgress['total_pages'] }}</progress>
            @elseif ($processingStage === 'extraction')
                <p>Reading your material. We’ll confirm when all pages have been extracted.</p>
            @elseif ($processingStage === 'relevance')
                <p>All pages have been extracted. Now we’re checking which pages match your learning goal.</p>
            @else
                <p>Page extraction and relevance checks are complete. Now we’re building your study guide from the matching pages.</p>
            @endif
            <ol class="processing-steps" aria-label="Upload progress">
                @foreach ([['Pages extracted', 0], ['Pages checked against your goal', 1], ['Study guide created', 2]] as [$stepLabel, $stepPosition])
                    @php($stepDone = $stagePosition > $stepPosition)
                    <li @if ($stagePosition === $stepPosition) aria-current="step" @endif>
                        <span>{{ $stepDone ? 'Complete' : ($stagePosition === $stepPosition ? 'In progress' : 'Next') }}</span>
                        <strong>{{ $stepLabel }}</strong>
                    </li>
                @endforeach
            </ol>
            <p class="muted">This page will update automatically. You can safely leave and return later.</p>
            @if ($otherReadyMaterialCount > 0)
                <aside class="notice stack">
                    <p>You can continue with {{ $otherReadyMaterialCount }} other ready {{ Illuminate\Support\Str::plural('material', $otherReadyMaterialCount) }} in this study space while this one is being prepared.</p>
                    <a href="{{ route('materials.index') }}#space-{{ $material->learning_space_id }}-materials">See your other ready materials</a>
                </aside>
            @endif
        </div>
        <script>window.setTimeout(() => window.location.reload(), 3000);</script>
    @elseif ($material->status === 'failed')
        @php($failedStage = $material->metadata['failed_stage'] ?? 'extraction')
        @php($failedPosition = ['extraction' => 0, 'relevance' => 1, 'concepts' => 2][$failedStage] ?? 0)
        @php($quotaLimited = str_contains($material->failure_message ?? '', 'insufficient_quota') || str_contains($material->failure_message ?? '', 'credit_balance_exhausted'))
        <div class="error" role="alert">
            <h2>{{ $failedStage === 'concepts' ? 'Error generating study guide' : 'We couldn’t finish processing your material' }}</h2>
            <ol class="processing-steps" aria-label="Completed upload steps">
                @foreach ([['Pages extracted', 0], ['Pages checked against your goal', 1], ['Study guide created', 2]] as [$stepLabel, $stepPosition])
                    <li>
                        <span>{{ $stepPosition < $failedPosition ? 'Complete' : ($stepPosition === $failedPosition ? 'Stopped here' : 'Not started') }}</span>
                        <strong>{{ $stepLabel }}</strong>
                    </li>
                @endforeach
            </ol>
            @if ($failedStage === 'concepts')
                <p>Your pages were extracted and checked against your goal. The problem happened while building the study guide. Your saved pages and relevance checks will be reused if you retry.</p>
            @elseif ($failedStage === 'relevance')
                <p>Your pages were extracted. The problem happened while checking which pages match your goal. Your extracted pages will be reused if you retry.</p>
            @else
                <p>The problem happened while reading your material. Any pages already saved will be kept for a retry.</p>
            @endif
            @if ($quotaLimited)
                <p>The AI service has reached its usage limit, so it could not create the study guide. Your extracted pages and relevance checks are saved. Try again after the usage limit is resolved.</p>
            @else
                <p>{{ $material->failure_message }}</p>
            @endif
            <form method="POST" action="{{ route('materials.retry', $material) }}">@csrf<button>Retry processing</button></form>
            @if ($otherReadyMaterialCount > 0)
                <aside class="notice stack">
                    <p>You can keep studying from {{ $otherReadyMaterialCount }} other ready {{ Illuminate\Support\Str::plural('material', $otherReadyMaterialCount) }} in this study space while you retry this one.</p>
                    <a href="{{ route('materials.index') }}#space-{{ $material->learning_space_id }}-materials">See your other ready materials</a>
                </aside>
            @endif
        </div>
    @else
        @php($relatedPages = $material->chunks->where('relevance_status', 'related'))
        @php($uncertainPages = $material->chunks->where('relevance_status', 'possibly_related'))
        @php($separatePages = $material->chunks->where('relevance_status', 'separate'))
        @php($pendingPages = $material->chunks->where('relevance_status', 'pending'))
        <section class="card stack" aria-labelledby="material-match-heading">
            <h2 id="material-match-heading">How this material fits your goal</h2>
            <p>We compare each extracted page with “{{ $material->learningSpace->goal ?: $material->learningSpace->title }}”. Only pages marked related are used in the study path, tutor, and practice. Your original material and all extracted pages remain available here.</p>
            <p><strong>{{ $relatedPages->count() }}</strong> related · <strong>{{ $uncertainPages->count() }}</strong> need your review · <strong>{{ $separatePages->count() }}</strong> kept separate · <strong>{{ $pendingPages->count() }}</strong> not checked</p>
            @if ($relatedPages->isNotEmpty() && ! $hasSourceLinkedConcept)
                <aside class="notice">
                    <p>No study concepts were created from these checked pages yet. Your extraction and page decisions are saved.</p>
                    <form method="POST" action="{{ route('materials.rebuild-guide', $material) }}">@csrf<button class="button-secondary">Build study guide from checked pages</button></form>
                </aside>
            @endif
            @foreach ($relatedPages as $chunk)
                @php($confirmedClock = $chunk->locator['clock_diagrams'] ?? null)
                @if (is_array($confirmedClock) && ($confirmedClock['status'] ?? null) === 'learner_confirmed')
                    <details>
                        <summary>Learner-confirmed clock readings · Page {{ $chunk->page ?? 'unknown' }}</summary>
                        <p>These times were checked against the original page.</p>
                        <ol class="stack">@foreach ($confirmedClock['faces'] as $face)<li>Clock {{ $face['index'] }}: {{ $face['confirmed_time'] }}</li>@endforeach</ol>
                    </details>
                @endif
            @endforeach
            @if ($pendingPages->isNotEmpty())
                <div class="stack" aria-label="Pages not yet matched to your goal">
                    @foreach ($pendingPages as $chunk)
                        <article class="stack">
                            <h3>Page {{ $chunk->page ?? 'unknown' }} · Not included yet</h3>
                            <p>This saved page hasn’t been checked against your goal, so it is not used in your study path, tutor, or practice yet.</p>
                            <details><summary>Review extracted page text</summary><x-material-extracted-text :content="$chunk->content" /><p><a href="{{ route('materials.download', $material) }}">Open the original material to compare</a></p></details>
                            <form method="POST" action="{{ route('materials.relevance', $material) }}">@csrf
                                <input type="hidden" name="chunk_id" value="{{ $chunk->id }}"><input type="hidden" name="decision" value="recheck">
                                <button>Check relevance</button>
                            </form>
                        </article>
                    @endforeach
                </div>
            @endif
            @if ($uncertainPages->isNotEmpty())
                <div class="stack" aria-label="Pages that may relate to your goal">
                    @foreach ($uncertainPages as $chunk)
                        <article class="stack">
                            <h3>Page {{ $chunk->page ?? 'unknown' }} · Not included yet</h3>
                            @php($reviewReason = (string) $chunk->relevance_reason)
                            @if (str_contains(mb_strtolower($reviewReason), 'mathpix') || str_contains(mb_strtolower($reviewReason), 'confidence rate'))
                                <p>We could not tell whether all the words on this page were read clearly. Compare it with the original before including it in your study guide.</p>
                            @elseif (str_contains(mb_strtolower($reviewReason), 'local clock reader'))
                                <p>Possible clock times were found on this page. Compare each one with the original and correct them before including this page in your study guide.</p>
                            @else
                                <p>{{ $reviewReason }}</p>
                            @endif
                            @php($clockAnalysis = $chunk->locator['clock_diagrams'] ?? null)
                            <details><summary>{{ is_array($clockAnalysis) ? 'Review page text and suggested times' : 'Review page text' }}</summary><x-material-extracted-text :content="($chunk->locator['ocr_content'] ?? $chunk->content)" /><p><a href="{{ route('materials.download', $material) }}">Open the original material to compare</a></p></details>
                            @if (is_array($clockAnalysis) && ! empty($clockAnalysis['faces']))
                                <form method="POST" action="{{ route('materials.relevance', $material) }}" class="stack">
                                    @csrf
                                    <input type="hidden" name="chunk_id" value="{{ $chunk->id }}">
                                    <fieldset class="stack">
                                        <legend>Check each clock against the original</legend>
                                        <p>These suggested times may need correcting. Check each clock against the original before adding the readings to your study guide.</p>
                                        @foreach ($clockAnalysis['faces'] as $face)
                                            <label for="clock-reading-{{ $chunk->id }}-{{ $face['index'] }}">Clock {{ $face['index'] }}@if (($face['match_quality'] ?? 'ambiguous') === 'ambiguous') (check carefully)@endif</label>
                                            <input id="clock-reading-{{ $chunk->id }}-{{ $face['index'] }}" name="clock_readings[{{ $face['index'] }}]" type="time" value="{{ $face['candidate_time'] ?? '' }}" required aria-describedby="clock-review-hint-{{ $chunk->id }}">
                                        @endforeach
                                        <p id="clock-review-hint-{{ $chunk->id }}" class="muted">Compare each suggested time with the original worksheet. You can edit the values.</p>
                                    </fieldset>
                                    <div class="actions">
                                        <button name="decision" value="related">Confirm readings and include page</button>
                                        <button class="button-secondary" name="decision" value="separate">Keep separate</button>
                                    </div>
                                </form>
                            @else
                                <div class="actions">
                                    <form method="POST" action="{{ route('materials.relevance', $material) }}">@csrf
                                        <input type="hidden" name="chunk_id" value="{{ $chunk->id }}"><button name="decision" value="related">Include this page</button>
                                    </form>
                                    <form method="POST" action="{{ route('materials.relevance', $material) }}">@csrf
                                        <input type="hidden" name="chunk_id" value="{{ $chunk->id }}"><button class="button-secondary" name="decision" value="separate">Keep separate</button>
                                    </form>
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
            @if ($separatePages->isNotEmpty())
                <details><summary>View {{ $separatePages->count() }} page(s) kept separate</summary>
                    @foreach ($separatePages as $chunk)<blockquote><strong>Page {{ $chunk->page ?? 'unknown' }}:</strong> {{ $chunk->relevance_reason }}</blockquote>@endforeach
                </details>
            @endif
        </section>
        @if (! empty($material->metadata['extraction_warnings']))
            <aside class="notice" aria-labelledby="extraction-warnings-heading"><h2 id="extraction-warnings-heading">A few pages may need checking</h2><p>Compare the pages marked for review with your original before studying.</p></aside>
        @endif
        @if ($material->role === 'past_paper' && $material->status === 'ready')
            @php($firstSourceLinkedConcept = $sourceLinkedConcepts->first())
            @php($firstStudyLessonUrl = $firstSourceLinkedConcept ? route('study.show', $material->learningSpace).'?concept='.$firstSourceLinkedConcept->id.'#concept-'.$firstSourceLinkedConcept->id : null)
            <section class="card stack post-upload-choice" aria-labelledby="post-upload-choice-heading">
                <div>
                    <p class="eyebrow">Your next step</p>
                    <h2 id="post-upload-choice-heading">How would you like to use this paper?</h2>
                    <p>Choose what feels useful now. You can change your mind later. You do not need to study the topics first to practise.</p>
                </div>

                @if ($sourceLinkedConcepts->isNotEmpty())
                    <div class="post-upload-topics">
                        <h3>Topics linked to this paper</h3>
                        <ul>
                            @foreach ($sourceLinkedConcepts->take(6) as $concept)
                                @php($topicPages = $concept->sources->where('material_id', $material->id)->where('extraction_version', $material->extraction_version)->where('relevance_status', 'related')->pluck('page')->filter()->unique()->sort()->values())
                                <li>
                                    <strong>{{ $concept->name }}</strong>
                                    @if ($topicPages->isNotEmpty())<span class="muted">Page{{ $topicPages->count() > 1 ? 's' : '' }} {{ $topicPages->join(', ') }}</span>@endif
                                    <a href="{{ route('study.show', $material->learningSpace).'?concept='.$concept->id.'#concept-'.$concept->id }}">Learn this topic</a>
                                    <details><summary>See the source pages</summary>
                                        @foreach ($concept->sources->where('material_id', $material->id)->where('extraction_version', $material->extraction_version)->where('relevance_status', 'related') as $source)
                                            <blockquote><strong>Page {{ $source->page ?? 'unknown' }}</strong><br>{{ Illuminate\Support\Str::limit($source->content, 600) }}</blockquote>
                                        @endforeach
                                        <a href="{{ route('materials.download', $material) }}">Open the original paper</a>
                                    </details>
                                </li>
                            @endforeach
                        </ul>
                        @if ($sourceLinkedConcepts->count() > 6)
                            <details><summary>See all {{ $sourceLinkedConcepts->count() }} topics</summary>
                                <ul>
                                    @foreach ($sourceLinkedConcepts->slice(6) as $concept)
                                        @php($topicPages = $concept->sources->where('material_id', $material->id)->where('extraction_version', $material->extraction_version)->where('relevance_status', 'related')->pluck('page')->filter()->unique()->sort()->values())
                                        <li>
                                            <strong>{{ $concept->name }}</strong>
                                            @if ($topicPages->isNotEmpty())<span class="muted">Page{{ $topicPages->count() > 1 ? 's' : '' }} {{ $topicPages->join(', ') }}</span>@endif
                                            <a href="{{ route('study.show', $material->learningSpace).'?concept='.$concept->id.'#concept-'.$concept->id }}">Learn this topic</a>
                                            <details><summary>See the source pages</summary>
                                                @foreach ($concept->sources->where('material_id', $material->id)->where('extraction_version', $material->extraction_version)->where('relevance_status', 'related') as $source)
                                                    <blockquote><strong>Page {{ $source->page ?? 'unknown' }}</strong><br>{{ Illuminate\Support\Str::limit($source->content, 600) }}</blockquote>
                                                @endforeach
                                                <a href="{{ route('materials.download', $material) }}">Open the original paper</a>
                                            </details>
                                        </li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                        <details class="post-upload-topic-selection" @if ($errors->has('focus_concepts')) open @endif>
                            <summary>Choose topics to focus on in your study path</summary>
                            <p>Select one or more topics from this paper. Your path will still include any foundations needed first.</p>
                            <form method="GET" action="{{ route('study.show', $material->learningSpace) }}" class="stack">
                                <input type="hidden" name="focus_material" value="{{ $material->uuid }}">
                                <fieldset class="stack">
                                    <legend>Topics from {{ $material->title }}</legend>
                                    @foreach ($sourceLinkedConcepts as $concept)
                                        @php($topicPages = $concept->sources->where('material_id', $material->id)->where('extraction_version', $material->extraction_version)->where('relevance_status', 'related')->pluck('page')->filter()->unique()->sort()->values())
                                        <label class="quiz-option" for="focus-topic-{{ $concept->id }}">
                                            <input id="focus-topic-{{ $concept->id }}" type="checkbox" name="focus_concepts[]" value="{{ $concept->id }}">
                                            <span><strong>{{ $concept->name }}</strong>@if ($topicPages->isNotEmpty()) <span class="muted">Page{{ $topicPages->count() > 1 ? 's' : '' }} {{ $topicPages->join(', ') }}</span>@endif</span>
                                        </label>
                                    @endforeach
                                </fieldset>
                                @if ($errors->has('focus_concepts') || collect(array_keys($errors->getMessages()))->contains(fn ($key) => str_starts_with($key, 'focus_concepts.')))
                                    <p class="error" role="alert">Choose one or more topics shown from this paper.</p>
                                @endif
                                <button type="submit">Use these topics in my study path</button>
                            </form>
                        </details>
                        <p class="muted">Only topics connected to pages in this paper that match your study goal are shown here. Question wording is checked separately.</p>
                    </div>
                @else
                    <p class="notice">There are no study topics linked to this paper yet. You can still practise any questions that are ready.</p>
                @endif

                <p class="post-upload-question-status"><strong>Practice questions:</strong>
                    @if ($readyPastPaperQuestionCount > 0)
                        {{ $readyPastPaperQuestionCount }} ready to practise.
                    @elseif ($pastPaperQuestions->isNotEmpty())
                        None are ready yet. {{ $heldPastPaperQuestionCount }} {{ Illuminate\Support\Str::plural('question', $heldPastPaperQuestionCount) }} {{ $heldPastPaperQuestionCount === 1 ? 'needs' : 'need' }} a check against the paper.
                    @else
                        Questions have not been prepared yet.
                    @endif
                </p>

                <div class="actions post-upload-choice-actions">
                    @if ($readyPastPaperQuestionCount > 0)
                        <a class="button-link" href="#past-paper-practice-start">Practise this paper</a>
                    @else
                        <a class="button-link" href="#past-paper-questions-heading">Find or review questions</a>
                    @endif
                    @if ($firstStudyLessonUrl)
                        <a class="button-secondary" href="{{ $firstStudyLessonUrl }}">Learn these topics first</a>
                    @endif
                    @if ($mixedReadyPaperCount >= 2)
                        <a href="{{ route('past-paper-mixed.index', $material->learningSpace) }}">Choose papers for mixed practice</a>
                    @endif
                </div>

                <details class="post-upload-help" @if ($choiceIntent !== null) open @endif>
                    <summary>Help me choose</summary>
                    <form method="GET" action="{{ route('materials.show', $material) }}#post-upload-choice-result" class="stack">
                        <fieldset class="stack">
                            <legend>What feels right to you today?</legend>
                            <label class="quiz-option"><input type="radio" name="learning_intent" value="practise" required @checked($choiceIntent === 'practise')> I want to try questions now</label>
                            <label class="quiz-option"><input type="radio" name="learning_intent" value="learn_first" required @checked($choiceIntent === 'learn_first')> I want to understand the topics first</label>
                            <label class="quiz-option"><input type="radio" name="learning_intent" value="unsure" required @checked($choiceIntent === 'unsure')> I am not sure yet</label>
                        </fieldset>
                        <button type="submit">Suggest a next step</button>
                    </form>
                </details>

                @if ($learningChoice)
                    <aside class="notice post-upload-choice-result" id="post-upload-choice-result" role="status" aria-live="polite" tabindex="-1">
                        <p>{{ $learningChoice['reason'] }}</p>
                        @if ($learningChoice['route'] === 'practise' && $readyPastPaperQuestionCount > 0)
                            <a class="button-link" href="#past-paper-practice-start">Go to exam practice</a>
                        @elseif ($learningChoice['route'] === 'learn_first' && $firstStudyLessonUrl)
                            <a class="button-link" href="{{ $firstStudyLessonUrl }}">Open your study path</a>
                        @elseif ($learningChoice['route'] === 'practise')
                            <a href="#past-paper-questions-heading">Check the practice questions</a>
                        @elseif ($learningChoice['route'] === 'learn_first' && ! $firstStudyLessonUrl)
                            <a href="#past-paper-questions-heading">Review this paper</a>
                        @endif
                        @if ($learningChoice['route'] === 'learn_first' && $readyPastPaperQuestionCount > 0)
                            <a href="#past-paper-practice-start">Practise this paper instead</a>
                        @elseif ($learningChoice['route'] === 'practise' && $firstStudyLessonUrl)
                            <a href="{{ $firstStudyLessonUrl }}">Learn these topics instead</a>
                        @endif
                    </aside>
                @endif
            </section>
        @endif
        @if ($material->role === 'past_paper')
            <section class="stack" aria-labelledby="past-paper-questions-heading">
                <div class="section-heading">
                    <div>
                        <p class="eyebrow">Past-paper question bank</p>
                        <h2 id="past-paper-questions-heading">Questions found in this paper</h2>
                    </div>
                    @if ($pastPaperQuestions->isEmpty())
                        <form method="POST" action="{{ route('materials.past-paper-questions.index', $material) }}">
                            @csrf<button class="button-secondary">Find questions on this paper</button>
                        </form>
                    @endif
                </div>
                @if ($pastPaperQuestions->isNotEmpty())
                    <details class="question-bank-refresh">
                        <summary>Questions missing or need updating?</summary>
                        <p>Check this paper again to look for more practice questions.</p>
                        <form method="POST" action="{{ route('materials.past-paper-questions.index', $material) }}">
                            @csrf<button class="button-secondary">Refresh questions from this paper</button>
                        </form>
                    </details>
                @endif
                <p>Questions are gathered from the pages of your paper. Compare each one with the original before practising. Any answer text found stays hidden until you submit.</p>
                @if ($readyPastPaperQuestionCount > 0)
                    <form id="past-paper-practice-start" method="POST" action="{{ route('past-paper-practice.start', $material) }}" class="paper-practice-start">
                        @csrf
                        <div>
                            <p><strong>{{ $readyPastPaperQuestionCount }} {{ Illuminate\Support\Str::plural('question', $readyPastPaperQuestionCount) }}</strong> can be practised together. Questions needing a check stay out of the session.</p>
                            <fieldset class="practice-duration">
                                <legend>Choose your practice time. It is not the paper's official time.</legend>
                                @foreach ([30, 60, 90] as $minutes)
                                    <label><input type="radio" name="duration_minutes" value="{{ $minutes }}" @checked($loop->first)> {{ $minutes }} min</label>
                                @endforeach
                            </fieldset>
                            @error('duration_minutes')<p class="error" role="alert">Choose 30, 60, or 90 minutes.</p>@enderror
                        </div>
                        <button>Start exam practice</button>
                    </form>
                @endif
                @error('paper_practice')<p class="error" role="alert">{{ $message }}</p>@enderror
                @if ($pastPaperQuestions->isEmpty())
                    <p class="muted">No question list is ready yet. Choose “Find questions on this paper” to build one.</p>
                @else
                    <ol class="past-paper-question-list">
                        @foreach ($pastPaperQuestions as $question)
                            <li class="past-paper-question-item">
                                <div class="past-paper-question-heading">
                                    <h3>Question {{ $question->question_number }} · Page {{ $question->page ?? 'unknown' }}</h3>
                                    <span class="status status-{{ in_array($question->review_status, App\Models\PastPaperQuestion::PRACTICE_READY_STATUSES, true) ? 'ready' : 'pending' }}">
                                        {{ $question->review_status === 'owner_checked' ? 'Checked by you' : ($question->review_status === 'ready' ? 'Ready to practise' : 'Needs review') }}
                                    </span>
                                </div>
                                @if ($question->marks)
                                    <p class="past-paper-question-marks muted">{{ $question->marks }} {{ Illuminate\Support\Str::plural('mark', $question->marks) }} shown in the paper</p>
                                @endif
                                <p class="past-paper-question-meta muted">{{ match ($question->question_type) {
                                    'single_choice' => 'Single-answer multiple choice',
                                    'multiple_select' => 'Select multiple answers',
                                    'true_false' => 'True or false',
                                    'fill_gap' => 'Fill in the gap',
                                    default => 'Written or structured response',
                                } }} · Paper: {{ $material->title }}
                                    @if ($question->paper_year)
                                        · Year {{ $question->paper_year }} from the file name or title
                                    @endif
                                    · {{ $question->answer_source === 'not_detected' ? 'No clear answer found in the paper' : 'Answer text from the paper is kept separate' }}
                                </p>
                                @if ($question->review_reason)
                                    <p class="notice">{{ $question->review_reason }} Check this wording against the original before practising.</p>
                                @endif
                                <blockquote class="past-paper-question-prompt" data-render-math>{{ $question->prompt }}</blockquote>
                                @if ($question->options)
                                    <ol class="past-paper-question-options" type="A">
                                        @foreach ($question->options as $option)
                                            <li data-render-math>{{ $option['text'] }}</li>
                                        @endforeach
                                    </ol>
                                @endif
                                <details class="past-paper-question-source">
                                    <summary>Compare with the text saved from this page</summary>
                                    <p><strong>Question text</strong></p>
                                    <blockquote data-render-math>{{ $question->source_text }}</blockquote>
                                    <a href="{{ route('materials.download', $material) }}">Open the original paper</a>
                                </details>
                                @if ($question->review_status === 'needs_review')
                                    <details class="past-paper-question-review">
                                        <summary>Check or correct this question</summary>
                                        <p>Compare with the original paper. Your correction will not change the saved page text or page reference. The answer key will not be used for scoring.</p>
                                        <form method="POST" action="{{ route('materials.past-paper-questions.review', [$material, $question]) }}" class="stack">
                                            @csrf
                                            <label for="question-prompt-{{ $question->id }}">Question wording</label>
                                            <textarea id="question-prompt-{{ $question->id }}" name="prompt" rows="4" required minlength="8" maxlength="10000">{{ old('prompt', $question->prompt) }}</textarea>
                                            @error('prompt')<p class="error" role="alert">{{ $message }}</p>@enderror
                                            <label for="question-type-{{ $question->id }}">Question format</label>
                                            <select id="question-type-{{ $question->id }}" name="question_type" required>
                                                @foreach (['single_choice' => 'Choose one answer', 'multiple_select' => 'Choose all that apply', 'true_false' => 'True or false', 'fill_gap' => 'Fill in the gap', 'structured_response' => 'Written answer'] as $type => $label)
                                                    <option value="{{ $type }}" @selected(old('question_type', $question->question_type) === $type)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            <fieldset class="stack">
                                                <legend>Answer choices, if this question has them</legend>
                                                @foreach (range('A', 'H') as $optionId)
                                                    <label for="question-option-{{ $question->id }}-{{ $optionId }}">Choice {{ $optionId }}</label>
                                                    <input id="question-option-{{ $question->id }}-{{ $optionId }}" name="options[{{ $optionId }}]" value="{{ old('options.'.$optionId, collect($question->options ?? [])->firstWhere('id', $optionId)['text'] ?? '') }}" maxlength="2000">
                                                @endforeach
                                                @error('options')<p class="error" role="alert">{{ $message }}</p>@enderror
                                            </fieldset>
                                            <button>Save checked question</button>
                                        </form>
                                    </details>
                                @endif
                                @if (in_array($question->review_status, App\Models\PastPaperQuestion::PRACTICE_READY_STATUSES, true))
                                    <a class="button-link past-paper-question-action" href="{{ route('past-paper-questions.practice', [$material, $question]) }}">Practise this question</a>
                                @else
                                    <p class="muted">This question is not available for practice until its wording and structure have been checked.</p>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                    <p class="muted">Clear questions can be practised together in paper order with a learner-selected practice timer. This is not an official or proctored exam, and the timer does not represent verified paper timing. Questions needing review stay out.</p>
                @endif
            </section>
        @endif
        <section><div class="section-heading"><div><p class="eyebrow">Your next step</p><h2>Source-linked concepts</h2></div><a class="button-link" href="{{ route('study.show', $material->learningSpace) }}">Open study path</a></div><div class="concept-grid">
            @foreach ($material->learningSpace->concepts as $concept)
                @if ($concept->sources->where('relevance_status', 'related')->where('material_id', $material->id)->where('extraction_version', $material->extraction_version)->isNotEmpty())
                    <article class="card"><h3>{{ $concept->name }}</h3><p data-render-math>{{ $concept->description }}</p>
                        @if ($concept->prerequisites->isNotEmpty())<p class="muted">Prerequisites: {{ $concept->prerequisites->pluck('name')->join(', ') }}</p>@endif
                        <details><summary>View source evidence</summary>@foreach ($concept->sources->where('relevance_status', 'related')->where('material_id', $material->id)->where('extraction_version', $material->extraction_version) as $source)<blockquote><strong>Page {{ $source->page ?? 'unknown' }}</strong><br>{{ $source->content }}</blockquote>@endforeach</details>
                    </article>
                @endif
            @endforeach
        </div></section>
    @endif
</x-layouts.app>
