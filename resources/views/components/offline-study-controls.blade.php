<section class="offline-controls" data-offline-controls
    data-pack-url="{{ route('offline.study-pack.show', $learningSpace) }}"
    data-space-id="{{ $learningSpace->getRouteKey() }}"
    data-space-title="{{ $learningSpace->title }}"
    data-user-id="{{ auth()->id() }}"
    aria-labelledby="offline-heading">
    <div>
        <p class="eyebrow">Low-bandwidth study</p>
        <h2 id="offline-heading">Keep this path on your device</h2>
        <p>Save the text lessons, source excerpts, previous explanations, and quizzes for offline study. Answers wait on this device until you reconnect for server grading.</p>
    </div>
    <div class="offline-actions">
        <button type="button" data-offline-download>Download for offline study</button>
        <a href="/offline-study" data-offline-open hidden>Open offline reader</a>
        <button type="button" class="link-button" data-offline-delete hidden>Delete downloaded pack</button>
    </div>
    <p data-offline-status role="status" aria-live="polite">This study path has not been downloaded on this device.</p>
</section>
