@props(['source'])
@php($figures = data_get($source->locator, 'figures', []))
@if (is_array($figures) && $figures !== [])
    <div class="source-figures" aria-label="Diagrams from {{ $source->material->title }}, page {{ $source->page ?? 'unknown' }}">
        @foreach ($figures as $index => $figure)
            <figure class="source-figure">
                <img src="{{ route('materials.page-figure', [$source->material, $source->page, $index + 1]) }}"
                    alt="{{ $figure['alt'] ?? 'Diagram or illustration from the source page' }}" loading="lazy">
                <figcaption>{{ $source->material->title }} · Page {{ $source->page ?? 'unknown' }}</figcaption>
            </figure>
        @endforeach
    </div>
@endif
