<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'SelfLearn' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @if (request()->routeIs('study.show') && request()->route('learningSpace') instanceof \App\Models\LearningSpace)
        <link rel="stylesheet" href="{{ asset('css/offline-study.css') }}">
    @endif
</head>
<body>
<header class="site-header">
    <a href="{{ route('materials.index') }}" class="brand">
        <span>SelfLearn</span>
        <svg class="brand-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M12 3v2M8 5h8a4 4 0 0 1 4 4v7a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4V9a4 4 0 0 1 4-4Z" />
            <path d="M8.5 11h.01M15.5 11h.01M9 15c.8.7 1.8 1 3 1s2.2-.3 3-1M2 10v5M22 10v5" />
        </svg>
    </a>
    @auth
        <div class="header-actions">
            <button type="button" class="link-button" data-open-accessibility>Accessibility preferences</button>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="link-button">Sign out</button>
                <p data-offline-logout-status role="status" aria-live="polite" hidden></p>
            </form>
        </div>
    @endauth
</header>
@auth
    <dialog class="accessibility-dialog" data-accessibility-dialog data-has-preference="{{ auth()->user()->accessibility_experience ? 'true' : 'false' }}" aria-labelledby="accessibility-dialog-title" aria-describedby="accessibility-dialog-description">
        <form method="POST" action="{{ route('accessibility-preferences.update') }}">
            @csrf
            <h2 id="accessibility-dialog-title">How would you like to use SelfLearn?</h2>
            <p id="accessibility-dialog-description">Choose what works best for you. You can change this any time. All learning content stays readable on screen.</p>
            <fieldset class="accessibility-choices">
                <legend>Preferred learning experience</legend>
                <label>
                    <input type="radio" name="experience" value="listen" @checked(auth()->user()->accessibility_experience === 'listen') @if(auth()->user()->accessibility_experience === 'listen') autofocus @endif>
                    <span><strong>Listen to content</strong><span>Choose Listen on a lesson, quiz, or exam to hear its text.</span></span>
                </label>
                <label>
                    <input type="radio" name="experience" value="read" @checked(auth()->user()->accessibility_experience !== 'listen') @if(auth()->user()->accessibility_experience !== 'listen') autofocus @endif>
                    <span><strong>Read on screen</strong><span>Keep the experience text-first. You can still use audio controls when offered.</span></span>
                </label>
            </fieldset>
            <p class="accessibility-dialog-note">This choice does not change screen-reader or keyboard support.</p>
            <div class="accessibility-dialog-actions">
                <button type="submit">Save preference</button>
                <button type="button" class="accessibility-secondary" data-close-accessibility>{{ auth()->user()->accessibility_experience ? 'Cancel' : 'Not now' }}</button>
            </div>
        </form>
    </dialog>
@endauth
<main class="container">
    @if (session('status')) <p class="notice" role="status">{{ session('status') }}</p> @endif
    @if ($errors->any())
        <div class="error" role="alert"><strong>Please fix the following:</strong><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    {{ $slot }}
@if (request()->routeIs('study.show') && request()->route('learningSpace') instanceof \App\Models\LearningSpace)
    @include('components.offline-study-controls', ['learningSpace' => request()->route('learningSpace')])
@endif
</main>
<script defer src="{{ asset('js/offline-study.js') }}"></script>
</body>
</html>
