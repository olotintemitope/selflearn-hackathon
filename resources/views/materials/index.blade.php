<x-layouts.app title="Study spaces — SelfLearn">
    <header class="hero">
        <p class="eyebrow">Study from everything you bring</p>
        <h1>One goal. All your materials.</h1>
        <p>Make a study space for an exam, a course, or a topic you want to learn. Add notes, syllabi, past papers, and references whenever you have them.</p>
    </header>

    <section class="card stack" aria-labelledby="new-space-heading">
        <div><p class="eyebrow">Start with your goal</p><h2 id="new-space-heading">Create a study space</h2><p class="muted">A study space can hold many materials. You can add more at any time.</p></div>
        <form method="POST" action="{{ route('spaces.store') }}" class="space-form">
            @csrf
            <label for="space-title">Space name <input id="space-title" name="title" value="{{ old('title') }}" maxlength="150" required autocomplete="off" placeholder="e.g. Prepare for Biology exam"></label>
            <label for="space-purpose">What are you studying for?
                <select id="space-purpose" name="purpose" required>
                    @foreach (\App\Models\LearningSpace::PURPOSES as $value => $label)
                        <option value="{{ $value }}" @selected(old('purpose', 'exam_prep') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label for="space-subject">Subject or topic <span class="optional-label">Optional</span><input id="space-subject" name="subject" value="{{ old('subject') }}" maxlength="120" placeholder="e.g. Biology"></label>
            <label for="space-level">Class, level, or course <span class="optional-label">Optional</span><input id="space-level" name="level" value="{{ old('level') }}" maxlength="120" placeholder="e.g. Senior secondary"></label>
            <label for="space-exam-date">Exam date <span class="optional-label">Optional</span><input id="space-exam-date" type="date" name="exam_date" value="{{ old('exam_date') }}"></label>
            <label for="space-goal">What would you like to achieve? <span class="optional-label">Optional</span><textarea id="space-goal" name="goal" rows="2" maxlength="500" placeholder="e.g. Understand the key ideas and prepare for essay questions">{{ old('goal') }}</textarea></label>
            <button>Create study space</button>
        </form>
    </section>

    <section aria-labelledby="spaces-heading">
        <div class="section-heading"><div><p class="eyebrow">Your materials stay together by goal</p><h2 id="spaces-heading">Your study spaces</h2></div></div>
        @forelse ($spaces as $space)
            <article class="card stack study-space" aria-labelledby="space-{{ $space->id }}-heading">
                <header class="space-heading">
                    <div>
                        <p class="eyebrow">{{ $space->purposeLabel() }}</p>
                        <h3 id="space-{{ $space->id }}-heading">{{ $space->title }}</h3>
                        @if ($space->subject || $space->level)<p class="muted">{{ collect([$space->subject, $space->level])->filter()->join(' · ') }}</p>@endif
                        @if ($space->goal)<p>{{ $space->goal }}</p>@endif
                        @if ($space->exam_date)<p class="muted">Exam date: <time datetime="{{ $space->exam_date->toDateString() }}">{{ $space->exam_date->format('j M Y') }}</time></p>@endif
                    </div>
                    <span class="space-count">{{ $space->materials->count() }} {{ \Illuminate\Support\Str::plural('material', $space->materials->count()) }}</span>
                </header>

                @if ($space->purpose === 'exam_prep')
                    <aside class="study-prompt"><strong>Preparing for an exam?</strong><p>For a fuller guide, add any syllabus, study notes, past papers, or marking schemes you have. These are suggestions; you can start with what you have and add more later.</p></aside>
                @endif

                <div class="space-materials" id="space-{{ $space->id }}-materials">
                    <div><h4>Materials in this space</h4><p class="muted">Each upload adds to the concepts and study path for this goal.</p></div>
                    <form method="POST" action="{{ route('materials.store') }}" enctype="multipart/form-data" class="upload-grid">
                        @csrf
                        <input type="hidden" name="learning_space_id" value="{{ $space->id }}">
                        <label>Material title <input name="title" value="{{ old('learning_space_id') == $space->id ? old('title') : '' }}" required maxlength="150" placeholder="e.g. Term 2 revision"></label>
                        <label>Kind of material
                            <select name="role" required>
                                @foreach (\App\Models\Material::ROLES as $value => $label)
                                    <option value="{{ $value }}" @selected(old('learning_space_id') == $space->id ? old('role', 'study_notes') === $value : $value === 'study_notes')>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>PDF or image <input type="file" name="material" accept="image/jpeg,image/png,image/webp,application/pdf" required></label>
                        <button>{{ $space->materials->isEmpty() ? 'Add material' : 'Add another material' }}</button>
                    </form>
                </div>

                <div class="material-list">
                    @forelse ($space->materials as $material)
                        <a class="material-row" href="{{ route('materials.show', $material) }}"><span class="material-name">{{ $material->title }}</span><span class="role-label">{{ $material->roleLabel() }}</span><span class="status status-{{ $material->status }}">{{ ucfirst($material->status) }}</span></a>
                    @empty
                        <p class="empty-state">No materials yet. Start with any document you have; you can add more later.</p>
                    @endforelse
                </div>
                @if ($space->materials->isNotEmpty())<a class="study-link" href="{{ route('study.show', $space) }}">Open this study path</a>@endif
            </article>
        @empty
            <p class="empty-state">Create a study space to start gathering materials around a learning goal.</p>
        @endforelse
    </section>
</x-layouts.app>
