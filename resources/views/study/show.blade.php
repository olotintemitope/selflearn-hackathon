<x-layouts.app :title="'Study path — '.$learningSpace->title">
    <a href="{{ route('materials.index') }}">← All materials</a>
    <header class="hero compact">
        <p class="eyebrow">Adaptive study</p>
        <h1>Your learning path</h1>
        <p>Study prerequisite ideas first, then use the tutor to ask questions grounded in your own materials.</p>
    </header>

    <section aria-labelledby="path-heading">
        <div class="section-heading"><div><p class="eyebrow">Path version {{ $path->version }}</p><h2 id="path-heading">Prerequisite-first order</h2></div></div>
        @if ($path->items->isEmpty())
            <p class="notice">Upload and process a material before building a study path.</p>
        @else
            <ol class="learning-path">
                @foreach ($path->items as $item)
                    <li class="card">
                        <span class="path-number" aria-hidden="true">{{ $item->position }}</span>
                        <div><h3>{{ $item->concept->name }}</h3><p>{{ $item->concept->description }}</p>
                        @if ($item->concept->prerequisites->isNotEmpty())<p class="muted">Builds on {{ $item->concept->prerequisites->pluck('name')->join(', ') }}</p>@endif</div>
                    </li>
                @endforeach
            </ol>
        @endif
    </section>

    <section class="tutor-section" aria-labelledby="tutor-heading">
        <p class="eyebrow">Grounded tutor</p><h2 id="tutor-heading">Ask your materials</h2>
        <p class="muted">Answers cite exact excerpts. Supplementary knowledge is labelled separately.</p>

        <div class="conversation" aria-live="polite">
            @forelse ($messages as $message)
                <article class="message message-{{ $message->role }} {{ $message->status === 'failed' ? 'error' : '' }}">
                    <h3>{{ $message->role === 'user' ? 'You' : 'Tutor' }}</h3>
                    @if ($message->status === 'failed')
                        <p>{{ $message->failure_message }}</p>
                    @else
                        <p>{{ $message->content }}</p>
                        @if ($message->insufficient_evidence)<p class="evidence-label">Insufficient source evidence</p>@endif
                        @foreach ($message->citations as $citation)
                            @php($chunk = \App\Models\MaterialChunk::with('material')->find($citation['chunk_id']))
                            @if ($chunk && $chunk->material->learning_space_id === $learningSpace->id)
                                <blockquote><strong>{{ $chunk->material->title }} · Page {{ $chunk->page ?? 'unknown' }}</strong><br>{{ $citation['excerpt'] }}</blockquote>
                            @endif
                        @endforeach
                        @foreach ($message->supplementary_sections as $section)
                            <aside class="supplementary"><strong>Supplementary: {{ $section['title'] }}</strong><p>{{ $section['content'] }}</p></aside>
                        @endforeach
                    @endif
                </article>
            @empty
                <p class="empty-state">No questions yet. Try asking about one concept in your path.</p>
            @endforelse
        </div>

        <form class="question-form" method="POST" action="{{ route('tutor.ask', $learningSpace) }}">
            @csrf
            <label for="question">Your question<textarea id="question" name="question" rows="4" maxlength="2000" required>{{ old('question') }}</textarea></label>
            <button>Ask the tutor</button>
        </form>
    </section>
</x-layouts.app>
