<x-layouts.app :title="'Choose past papers | '.$learningSpace->title">
    <section class="stack paper-run" aria-labelledby="mixed-paper-heading">
        <a href="{{ route('study.show', $learningSpace) }}">Back to your study path</a>
        <header>
            <p class="eyebrow">{{ $learningSpace->title }} · Exam practice</p>
            <h1 id="mixed-paper-heading">Choose papers to practise</h1>
            <p class="lede mixed-paper-intro">Choose at least two papers. Check the topics under each paper before you put them together. Every question stays linked to its paper and page.</p>
        </header>

        @if ($papers->count() < 2)
            <section class="card stack" aria-labelledby="mixed-paper-empty-heading">
                <h2 id="mixed-paper-empty-heading">{{ $papers->isEmpty() ? 'Add past papers to make a mixed set' : 'Add one more paper to make a mixed set' }}</h2>
                <p>This study space needs at least two past papers with clear questions. Questions that still need checking stay out of practice.</p>
                <a href="{{ route('materials.index') }}#space-{{ $learningSpace->id }}-materials">Add a paper to this study space</a>
            </section>
        @else
            <form method="POST" action="{{ route('past-paper-mixed.start', $learningSpace) }}" class="stack paper-mixed-select">
                @csrf
                @error('material_ids')<p class="error" role="alert">{{ $message }}</p>@enderror
                <fieldset class="stack mixed-paper-options">
                    <legend>Choose papers for one practice set</legend>
                    @foreach ($papers as $paper)
                        <article class="mixed-paper-option">
                            <label class="quiz-option" for="mixed-paper-{{ $paper->id }}">
                                <input id="mixed-paper-{{ $paper->id }}" type="checkbox" name="material_ids[]" value="{{ $paper->id }}" @checked(in_array((string) $paper->id, old('material_ids', []), true))>
                                <span>
                                    <strong>{{ $paper->title }}</strong><br>
                                    <span>{{ $paper->ready_question_count }} clear {{ Illuminate\Support\Str::plural('question', $paper->ready_question_count) }} ready</span>
                                    @if ($paper->paper_year_hint)
                                        <span> · Year {{ $paper->paper_year_hint }} from the paper title or file name</span>
                                    @endif
                                </span>
                            </label>
                            <div class="mixed-paper-topics" aria-label="Topics linked to {{ $paper->title }}">
                                <strong>Topics linked to this paper</strong>
                                @if ($paper->related_topic_evidence->isNotEmpty())
                                    <ul>
                                        @foreach ($paper->related_topic_evidence as $topic)
                                            <li>{{ $topic['name'] }}@if ($topic['pages']->isNotEmpty()) <span class="muted">· Page{{ $topic['pages']->count() > 1 ? 's' : '' }} {{ $topic['pages']->join(', ') }}</span>@endif</li>
                                        @endforeach
                                    </ul>
                                @else
                                    <p class="muted">No related study topics have been identified for this paper yet. Check its subject before adding it to a set.</p>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </fieldset>
                <fieldset class="practice-duration">
                    <legend>Choose your practice time. This is not official paper timing.</legend>
                    @foreach ([30, 60, 90] as $minutes)
                        <label><input type="radio" name="duration_minutes" value="{{ $minutes }}" @checked(old('duration_minutes', 30) == $minutes)> {{ $minutes }} minutes</label>
                    @endforeach
                </fieldset>
                @error('duration_minutes')<p class="error" role="alert">Choose 30, 60, or 90 minutes.</p>@enderror
                <p class="muted">If the topics differ, remove a paper or continue only if you want to practise them together. Questions follow the selected papers, then each paper’s page order. Year and marks are clues from the file name or page. They have not been checked against official records, and marks are not added into a score.</p>
                <label class="quiz-option mixed-paper-confirm" for="topic-grouping-confirmed">
                    <input id="topic-grouping-confirmed" type="checkbox" name="topic_grouping_confirmed" value="1" required @checked(old('topic_grouping_confirmed'))>
                    <span>I checked the topics and want these papers in one practice set, even if the topics differ.</span>
                </label>
                @error('topic_grouping_confirmed')<p class="error" role="alert">{{ $message }}</p>@enderror
                <button type="submit">Start mixed practice</button>
            </form>
        @endif
    </section>
</x-layouts.app>
