<x-layouts.app :title="$material->title.' — SelfLearn'">
    <a href="{{ route('materials.index') }}#space-{{ $material->learning_space_id }}-materials">← Back to this study space</a>
    <section class="hero compact">
        <p class="eyebrow">{{ $material->learningSpace->title }} · {{ $material->roleLabel() }}</p>
        <h1>{{ $material->title }}</h1>
        <div class="actions"><span class="status status-{{ $material->status }}">{{ ucfirst($material->status) }}</span><a href="{{ route('materials.download', $material) }}">Download original</a></div>
    </section>
    @if (in_array($material->status, ['pending', 'processing']))
        <div class="card" role="status" aria-live="polite">
            <h2>Preparing your study guide</h2>
            <p>We’re reading your material and organizing its key concepts. This page will update automatically when it’s ready.</p>
            <p class="muted">You can safely leave this page and return later.</p>
        </div>
        <script>window.setTimeout(() => window.location.reload(), 3000);</script>
    @elseif ($material->status === 'failed')
        <div class="error"><h2>Processing stopped</h2><p>{{ $material->failure_message }}</p><form method="POST" action="{{ route('materials.retry', $material) }}">@csrf<button>Retry processing</button></form></div>
    @else
        @if (($material->metadata['ocr_confidence'] ?? 1) < 0.8)<p class="notice">OCR confidence is low. Compare concepts with the original before studying.</p>@endif
        @if (! empty($material->metadata['extraction_warnings']))
            <aside class="notice" aria-labelledby="extraction-warnings-heading"><h2 id="extraction-warnings-heading">Extraction notes</h2><ul>@foreach ($material->metadata['extraction_warnings'] as $warning)<li>{{ $warning }}</li>@endforeach</ul></aside>
        @endif
        <section><div class="section-heading"><div><p class="eyebrow">Your next step</p><h2>Source-linked concepts</h2></div><a class="button-link" href="{{ route('study.show', $material->learningSpace) }}">Open study path</a></div><div class="concept-grid">
            @foreach ($material->learningSpace->concepts as $concept)
                @if ($concept->sources->whereIn('material_id', [$material->id])->isNotEmpty())
                    <article class="card"><h3>{{ $concept->name }}</h3><p>{{ $concept->description }}</p>
                        @if ($concept->prerequisites->isNotEmpty())<p class="muted">Prerequisites: {{ $concept->prerequisites->pluck('name')->join(', ') }}</p>@endif
                        <details><summary>View source evidence</summary>@foreach ($concept->sources->where('material_id', $material->id) as $source)<blockquote><strong>Page {{ $source->page ?? 'unknown' }}</strong><br>{{ $source->content }}</blockquote>@endforeach</details>
                    </article>
                @endif
            @endforeach
        </div></section>
    @endif
</x-layouts.app>
