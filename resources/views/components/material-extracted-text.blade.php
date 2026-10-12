@php($segments = \App\Support\ExtractedTextFormatter::segments($content))
<div class="extracted-text">
    <p class="muted">Section headings are formatted for readability. The saved transcription is unchanged.</p>
    @foreach ($segments as $segment)
        @if ($segment['type'] === 'heading')
            @if ($segment['level'] === 4)<h4>{{ $segment['text'] }}</h4>
            @elseif ($segment['level'] === 5)<h5>{{ $segment['text'] }}</h5>
            @else<h6>{{ $segment['text'] }}</h6>@endif
        @elseif (trim($segment['text']) !== '')
            <p class="extracted-text-copy">{{ $segment['text'] }}</p>
        @endif
    @endforeach
</div>
