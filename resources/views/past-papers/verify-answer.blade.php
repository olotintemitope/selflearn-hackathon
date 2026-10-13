<x-layouts.app :title="'Check answer · Question '.$question->question_number">
    <div class="stack answer-verification-page">
        <a href="{{ route('materials.show', $material) }}#past-paper-question-{{ $question->id }}">Back to {{ $material->title }}</a>
        <header class="stack">
            <p class="eyebrow">Check an answer</p>
            <h1>Question {{ $question->question_number }}</h1>
            <p class="lede">Compare this question with its original page and the answer key's original page. Only an answer you confirm here can be used to score future practice.</p>
        </header>

        @if ($pages->isEmpty())
            <section class="notice" aria-labelledby="answer-key-pages-unavailable">
                <h2 id="answer-key-pages-unavailable">No answer-key page is ready to compare</h2>
                <p>There is no extracted page text for the linked answer key yet. Check that the answer key has finished processing, then try again.</p>
                <a href="{{ route('materials.show', $match->markingScheme) }}">Open {{ $match->markingScheme->title }}</a>
            </section>
        @else
            <form method="GET" action="{{ route('past-paper-questions.verify-answer', [$material, $question]) }}" class="stack answer-source-selector">
                <div>
                    <label for="answer-key-match">Linked answer key</label>
                    <select id="answer-key-match" name="match" required>
                        @foreach ($matches as $availableMatch)
                            <option value="{{ $availableMatch->id }}" @selected($match->id === $availableMatch->id)>{{ $availableMatch->markingScheme->title }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="answer-key-page">Page with this answer</label>
                    <select id="answer-key-page" name="page" required>
                        @foreach ($pages as $pageNumber => $chunks)
                            <option value="{{ $pageNumber }}" @selected((int) $page === (int) $pageNumber)>Page {{ $pageNumber }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="button-secondary">Show these source pages</button>
            </form>

            <div class="answer-source-comparison" aria-label="Original source pages to compare">
                <section class="answer-source-panel" aria-labelledby="question-source-heading">
                    <h2 id="question-source-heading">Question page</h2>
                    <p class="muted">{{ $material->title }} · page {{ $question->page ?? 'not listed' }} · question {{ $question->question_number }}</p>
                    <p class="answer-question-prompt" data-render-math>{{ $question->prompt }}</p>
                    @if ($question->options)
                        <ol class="past-paper-question-options" type="A">
                            @foreach ($question->options as $option)
                                <li data-render-math>{{ $option['text'] }}</li>
                            @endforeach
                        </ol>
                    @endif
                    <p><a href="{{ route('materials.download', $material) }}" target="_blank" rel="noopener">Open the original question paper</a></p>
                    @if ($material->mime_type === 'application/pdf')
                        <iframe class="answer-original-preview" title="Original question paper, page {{ $question->page ?? 1 }}" src="{{ route('materials.preview', $material) }}#page={{ $question->page ?? 1 }}&amp;toolbar=0" loading="lazy"></iframe>
                    @elseif (str_starts_with($material->mime_type, 'image/'))
                        <img class="answer-original-image" src="{{ route('materials.preview', $material) }}" alt="Original uploaded question page">
                    @endif
                    <details>
                        <summary>Text read from this question page</summary>
                        <blockquote class="answer-source-transcription">{{ $questionChunk?->content ?? $question->source_text }}</blockquote>
                    </details>
                </section>

                <section class="answer-source-panel" aria-labelledby="key-source-heading">
                    <h2 id="key-source-heading">Answer-key page {{ $page }}</h2>
                    <p class="muted">{{ $match->markingScheme->title }} · page {{ $page }}</p>
                    <p><a href="{{ route('materials.download', $match->markingScheme) }}" target="_blank" rel="noopener">Open the original answer key</a></p>
                    @if ($match->markingScheme->mime_type === 'application/pdf')
                        <iframe class="answer-original-preview" title="Original answer key, page {{ $page }}" src="{{ route('materials.preview', $match->markingScheme) }}#page={{ $page }}&amp;toolbar=0" loading="lazy"></iframe>
                    @elseif (str_starts_with($match->markingScheme->mime_type, 'image/'))
                        <img class="answer-original-image" src="{{ route('materials.preview', $match->markingScheme) }}" alt="Original uploaded answer-key page">
                    @endif
                    <details open>
                        <summary>Text read from answer-key page {{ $page }}</summary>
                        @foreach ($answerKeyChunks as $chunk)
                            <blockquote class="answer-source-transcription">{{ $chunk->content }}</blockquote>
                        @endforeach
                    </details>
                </section>
            </div>

            @if ($answerKeyChunks->isNotEmpty())
                <section class="quiz-question-surface" aria-labelledby="confirm-answer-heading">
                    <h2 id="confirm-answer-heading">Choose the answer shown by this key</h2>
                    <p>Copy a short, exact passage from the answer-key page. Include the question number when possible. The passage is checked against the saved page text.</p>
                    <form method="POST" action="{{ route('past-paper-questions.verify-answer.store', [$material, $question]) }}" class="stack">
                        @csrf
                        <input type="hidden" name="marking_scheme_match_id" value="{{ $match->id }}">
                        <input type="hidden" name="source_page" value="{{ $page }}">

                        <fieldset class="answer-option-verification">
                            <legend>{{ $question->question_type === 'multiple_select' ? 'Select all answers that the key gives for this question' : 'Select the answer that the key gives for this question' }}</legend>
                            @foreach ($question->options ?? [] as $option)
                                <label class="quiz-option" for="verified-answer-{{ $question->id }}-{{ $option['id'] }}">
                                    @if ($question->question_type === 'multiple_select')
                                        <input id="verified-answer-{{ $question->id }}-{{ $option['id'] }}" type="checkbox" name="answer_option_ids[]" value="{{ $option['id'] }}" @checked(in_array($option['id'], old('answer_option_ids', $verifiedAnswer?->option_ids ?? []), true))>
                                    @else
                                        <input id="verified-answer-{{ $question->id }}-{{ $option['id'] }}" type="radio" name="answer_option_ids[]" value="{{ $option['id'] }}" @checked(in_array($option['id'], old('answer_option_ids', $verifiedAnswer?->option_ids ?? []), true)) required>
                                    @endif
                                    <span class="option-id">{{ $option['id'] }}</span>
                                    <span data-render-math>{{ $option['text'] }}</span>
                                </label>
                            @endforeach
                        </fieldset>
                        @error('answer_option_ids')<p class="error" role="alert">{{ $message }}</p>@enderror
                        @error('answer_option_ids.*')<p class="error" role="alert">{{ $message }}</p>@enderror

                        <label for="answer-key-excerpt">Exact words from the answer key</label>
                        <textarea id="answer-key-excerpt" name="answer_excerpt" rows="3" maxlength="1500" required>{{ old('answer_excerpt', $verifiedAnswer?->answer_excerpt) }}</textarea>
                        @error('answer_excerpt')<p class="error" role="alert">{{ $message }}</p>@enderror

                        <label class="quiz-option" for="confirm-answer-sources">
                            <input id="confirm-answer-sources" type="checkbox" name="confirm_sources" value="1" @checked(old('confirm_sources')) required>
                            <span>I checked question {{ $question->question_number }} on the original paper and this answer on the original answer key page {{ $page }}. The answer belongs to this question.</span>
                        </label>
                        @error('confirm_sources')<p class="error" role="alert">Confirm that you checked both original pages.</p>@enderror

                        <button>Confirm answer for future practice</button>
                    </form>
                    <p class="muted">This saves your confirmation for future practice only. Earlier results will not change.</p>
                </section>
            @endif
        @endif
    </div>
</x-layouts.app>
