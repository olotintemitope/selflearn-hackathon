@props(['target', 'language' => 'en'])

<section class="read-aloud-controls" data-read-aloud-controls data-read-aloud-target="{{ $target }}" data-read-aloud-language="{{ $language }}" data-read-aloud-url="{{ route('read-aloud') }}" aria-label="Read this content aloud">
    <div class="read-aloud-actions">
        <button type="button" data-read-aloud-play>Listen to this</button>
        <button type="button" class="read-aloud-secondary" data-read-aloud-pause disabled>Pause</button>
        <button type="button" class="read-aloud-secondary" data-read-aloud-stop disabled>Stop</button>
    </div>
    <audio class="read-aloud-player" controls hidden preload="metadata" aria-label="Audio playback"></audio>
    <p class="read-aloud-status" data-read-aloud-status role="status" aria-live="polite">Optional AI-generated speech. Choose Listen to hear this content.</p>
    <p class="muted">Choose Listen to hear this text in an AI voice. The text you select is sent to create the audio.</p>
</section>
