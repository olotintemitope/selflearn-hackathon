<x-layouts.app :title="$material->title.' — SelfLearn'">
    <a href="{{ route('materials.index') }}">← All materials</a>
    <section class="hero compact">
        <p class="eyebrow">{{ $material->original_filename }}</p>
        <h1>{{ $material->title }}</h1>
        <div class="actions"><span class="status status-{{ $material->status }}">{{ ucfirst($material->status) }}</span><a href="{{ route('materials.download', $material) }}">Download original</a></div>
    </section>
    @if (in_array($material->status, ['pending', 'processing']))
        <div class="card"><h2>Processing your material</h2><p>Run the queue worker with <code>php artisan queue:work</code>. Refresh this page to see extracted concepts.</p></div>
    @elseif ($material->status === 'failed')
        <div class="error"><h2>Processing stopped</h2><p>{{ $material->failure_message }}</p><form method="POST" action="{{ route('materials.retry', $material) }}">@csrf<button>Retry processing</button></form></div>
    @else
        @if (($material->metadata['ocr_confidence'] ?? 1) < 0.8)<p class="notice">OCR confidence is low. Compare concepts with the original before studying.</p>@endif
        <section><h2>Source-linked concepts</h2><div class="concept-grid">
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
